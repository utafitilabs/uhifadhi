<?php

declare(strict_types=1);

/*
 * This file is part of the Uhifadhi core.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Core\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Bundle\TeamBundle\Entity\User;

/**
 * THE INSTALLATION'S MANUAL, READ IN THE APP: the page at `/manual/`, its
 * pictures under `/manual/img/`, the PDF to download, and a Manual row at the
 * foot of the sidebar — for everybody signed in, and for nobody else.
 *
 * The throwaway application keeps a manual the way an installation does, in
 * `docs/handbook/` beside its code, named by `shell.manual`.
 */
final class ManualTest extends FieldApiTestCase
{
    public function testSignedOutEveryAddressSendsYouToSignIn(): void
    {
        foreach (['/manual/', '/manual/img/cover.png', '/manual/manual.pdf'] as $path) {
            $this->client->request('GET', $path);

            self::assertResponseRedirects('/login', null, $path);
        }
    }

    public function testThePageIsTheInstallationsOwnDocumentServedAsItIs(): void
    {
        $this->signIn();

        $this->client->request('GET', '/manual/');

        $response = $this->client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame((string) file_get_contents($this->folder().'/handbook.html'), $this->body($response));
        self::assertStringNotContainsString('class="shell"', $this->body($response), 'the manual is a document of its own, not a page in the frame');
        $this->assertPrivate($response);
    }

    /** `/manual` without its slash would resolve `img/…` one level too high. */
    public function testTheBareAddressGoesToTheSlashSoThePagesOwnLinksResolve(): void
    {
        $this->signIn();

        $this->client->request('GET', '/manual');

        self::assertResponseRedirects('http://localhost/manual/', Response::HTTP_MOVED_PERMANENTLY);
    }

    public function testAPictureThePageLinksIsServedWithItsType(): void
    {
        $this->signIn();

        $page = $this->client->request('GET', '/manual/');
        $src = (string) $page->filter('img')->attr('src');
        $this->client->request('GET', '/manual/'.$src);

        $response = $this->client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame((string) file_get_contents($this->folder().'/img/cover.png'), $this->body($response));
        $this->assertPrivate($response);
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function badPictures(): \Generator
    {
        yield 'no such picture' => ['/manual/img/absent.png'];
        yield 'not a picture' => ['/manual/img/handbook.html'];
        yield 'a parent directory, encoded' => ['/manual/img/..%2Fhandbook.pdf'];
        yield 'a hidden name' => ['/manual/img/.cover.png'];
        yield 'upper case' => ['/manual/img/COVER.PNG'];
        yield 'two levels' => ['/manual/img/sub/cover.png'];
    }

    #[DataProvider('badPictures')]
    public function testANameThatIsNotAPictureOfTheManualIsNotFound(string $path): void
    {
        $this->signIn();

        $this->client->request('GET', $path);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testThePdfIsADownloadNamedAfterTheFolder(): void
    {
        $this->signIn();

        $this->client->request('GET', '/manual/manual.pdf');

        $response = $this->client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=handbook.pdf', $response->headers->get('Content-Disposition'));
        $this->assertPrivate($response);
    }

    public function testTheSidebarOpensTheManualInANewTabAtItsFoot(): void
    {
        $this->signIn();

        $page = $this->client->request('GET', '/me/profile');
        self::assertResponseIsSuccessful();

        $headings = $page->filter('nav.nav .nav-hd')->each(static fn (Crawler $c): string => $c->text());
        self::assertSame('Help', end($headings), 'Help is the last group');

        $row = $page->filter('nav.nav a.nav-item[href="/manual/"]');
        self::assertCount(1, $row);
        self::assertSame('Manual', $row->text());
        self::assertSame('_blank', $row->attr('target'));
        self::assertSame('noopener', $row->attr('rel'));
        self::assertCount(1, $row->filter('svg'), 'the row carries its book');
    }

    private function signIn(): User
    {
        // Somebody with no position at all: being signed in is the whole of it.
        $user = $this->officeStaff('Neema', 'Mollel');
        $this->client->loginUser($user);

        return $user;
    }

    private function folder(): string
    {
        return \dirname(__DIR__).'/Application/docs/handbook';
    }

    private function body(Response $response): string
    {
        if ($response instanceof BinaryFileResponse) {
            return (string) file_get_contents($response->getFile()->getPathname());
        }

        return (string) $response->getContent();
    }

    private function assertPrivate(Response $response): void
    {
        self::assertTrue($response->headers->hasCacheControlDirective('private'), (string) $response->headers->get('Cache-Control'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-cache'), (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }
}
