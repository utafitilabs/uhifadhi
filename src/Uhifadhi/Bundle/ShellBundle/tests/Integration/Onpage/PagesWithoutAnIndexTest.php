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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Integration\Onpage;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\Fixtures\ImportingHostKernel;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\ShellKernelTestCase;

/**
 * A PAGE WITHOUT AN INDEX IS THE PAGE IT WAS. The welcome page, served over
 * HTTP through the shell's own controller, still renders in its frame and
 * carries nothing of the index: no grid, no pre-paint snippet, no rail class.
 */
final class PagesWithoutAnIndexTest extends ShellKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return ImportingHostKernel::class;
    }

    public function testTheWelcomePageCarriesNothingOfTheIndex(): void
    {
        $kernel = self::bootKernel();
        $response = $kernel->handle(Request::create('/'), catch: false);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $html = (string) $response->getContent();
        $page = new Crawler($html);

        self::assertCount(1, $page->filter('main.main div.page'), 'the frame still owns one .page');
        self::assertCount(1, $page->filter('div.pghead h1.pg'));
        self::assertCount(0, $page->filter('.onpage, .onpage-nav, [data-controller~="uhifadhi--shell-bundle--onpage"]'));
        self::assertStringNotContainsString('uhifadhi-pp-nav', $html);
        self::assertStringNotContainsString('onpage-closed', $html);
    }
}
