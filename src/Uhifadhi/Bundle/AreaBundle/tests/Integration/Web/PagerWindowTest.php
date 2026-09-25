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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * THE PAGER SHOWS A WINDOW OF PAGES, AND A SECOND LIST PAGES ITSELF. Two
 * facts the Stations tab taught with fifty-seven stations and three hundred
 * people: a pager that prints every page number is a row that stretches the
 * card past the page, and a second list's pager that inherits the page's own
 * `query` through the include context links the first list's pages instead
 * of its own. The design draws `1 2 3 … 8`; the second list names its
 * parameter, and that name decides.
 */
#[CoversNothing]
final class PagerWindowTest extends WebTestCase
{
    public function testASecondListPagesByItsOwnParameterInAWindowWithGaps(): void
    {
        $this->boot();

        $html = $this->render(['base' => '/here', 'param' => 'ppage', 'page' => 1, 'pages' => 38, 'from' => 1, 'to' => 8, 'total' => 304, 'noun' => 'people']);
        $crawler = new Crawler($html);

        self::assertSame(['/here?ppage=1', '/here?ppage=2', '/here?ppage=3', '/here?ppage=38', '/here?ppage=2'], $crawler->filter('.pgr a')->each(static fn (Crawler $a): string => (string) $a->attr('href')), 'previous, the window, the last page, next — every link on the second list\'s own parameter');
        self::assertSame(['1', '…'], $crawler->filter('.pgr span.pg-n')->each(static fn (Crawler $s): string => $s->text()), 'the page you are on, then one gap before the last page');
        self::assertCount(5, $crawler->filter('.pgr a'));
    }

    public function testTheWindowFollowsThePageYouAreOn(): void
    {
        $this->boot();

        $crawler = new Crawler($this->render(['base' => '/here', 'param' => 'ppage', 'page' => 20, 'pages' => 38, 'from' => 153, 'to' => 160, 'total' => 304, 'noun' => 'people']));

        self::assertSame(['← Previous', '1', '19', '21', '38', 'Next →'], $crawler->filter('.pgr a')->each(static fn (Crawler $a): string => trim($a->text())));
        self::assertSame(['…', '20', '…'], $crawler->filter('.pgr span.pg-n')->each(static fn (Crawler $s): string => $s->text()), 'a gap on each side of the window');
    }

    /** @param array<string, mixed> $with */
    private function render(array $with): string
    {
        $twig = static::getContainer()->get('twig');
        \assert($twig instanceof Environment);

        // The page's own `query` is in the context, as it is on every page
        // that carries two lists: the second list's parameter must still win.
        return $twig->render('@Area/_partial/_pager.html.twig', $with + ['query' => new \stdClass()]);
    }
}
