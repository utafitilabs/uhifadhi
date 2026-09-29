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

use Symfony\Component\DomCrawler\Crawler;

/**
 * THE REGISTER'S OPERATIONAL COLUMNS ARE LABELS, AND THE AREAS ARE UNALIKE.
 *
 * AN INSTALLATION DOES NOT RUN THE SAME MODULES EVERYWHERE. One area has
 * patrols and incidents switched on, its neighbour only patrols — so the
 * columns, headed from the first live area, reach a row that has nothing to
 * say in one of them.
 *
 * READ BY POSITION THAT IS WRONG TWICE. The shorter row printed its figures
 * under somebody else's heading, and where it had fewer than the headings it
 * asked for an index it does not have — which, with strict variables, is how
 * the whole library answered 500 in a real installation while this bundle's
 * own suite, whose every area was alike, rendered it green.
 */
final class RegisterColumnsAreLabelsTest extends WebTestCase
{
    /** THE PAGE OPENS AT ALL, which is the half that was 500ing. */
    public function testTheLibraryRendersOverAreasRunningDifferentModules(): void
    {
        $this->twoUnalikeAreas();

        $this->browser()->request('GET', '/areas/widgets');

        self::assertSame(200, $this->browser()->getResponse()->getStatusCode());
    }

    /** And every row's figures stand under the heading they are figures FOR. */
    public function testEachFigureStandsUnderTheColumnItIsAFigureFor(): void
    {
        $this->twoUnalikeAreas();

        $crawler = $this->browser()->request('GET', '/areas/widgets');
        $table = $crawler->filter('table.ax-reg');

        $columns = $table->filter('tr th')->each(static fn (Crawler $c): string => trim(explode('↓', $c->text())[0]));
        $patrols = array_search('patrols this wk', $columns, true);
        self::assertIsInt($patrols, 'the columns are headed from the area that contributes them');

        $cells = $table->filter('tr[data-row]')->each(
            static fn (Crawler $row): string => trim($row->filter('td')->eq($patrols)->text()),
        );

        // Kilimani answers for it; Sinde Flats runs a module that counts
        // something else entirely and says nothing here — rather than its own
        // figure standing under a heading that is not its own.
        self::assertSame(['23', '—'], $cells);
    }

    /**
     * TWO LIVE AREAS RUNNING DIFFERENT MODULES, and the register reads them
     * in name order — so the columns are headed from Kilimani's three
     * figures and Sinde Flats, which runs something else, can answer for none
     * of them.
     */
    private function twoUnalikeAreas(): void
    {
        $this->boot();

        $this->aLiveArea('Kilimani');

        $elsewhere = $this->anArea('Sinde Flats');
        $this->switchOn($elsewhere, 'incidents', 'Incidents');
    }
}
