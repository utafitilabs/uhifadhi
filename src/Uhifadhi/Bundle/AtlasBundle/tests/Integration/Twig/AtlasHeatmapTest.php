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

namespace Uhifadhi\Bundle\AtlasBundle\Tests\Integration\Twig;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatBand;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatCell;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatChip;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatColumn;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatLegend;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatLegendEntry;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatRow;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatTable;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatTint;
use Uhifadhi\Bundle\AtlasBundle\Model\Sparkline;
use Uhifadhi\Bundle\AtlasBundle\Model\SparkSize;
use Uhifadhi\Bundle\AtlasBundle\Model\SparkTone;
use Uhifadhi\Bundle\AtlasBundle\Tests\Integration\TestKernel;
use Uhifadhi\Bundle\AtlasBundle\Twig\HeatmapRuntime;

/**
 * `atlas_heatmap()` AND `atlas_heat_legend()` IN A REAL CONTAINER: the heat
 * table's own markup — a sortable head, a band rule, a row per thing and a
 * heat cell per column — and the legend that says what a tint is and is not.
 */
#[CoversClass(HeatmapRuntime::class)]
final class AtlasHeatmapTest extends TestCase
{
    public function testTheTableIsTheHeatGrammar(): void
    {
        $html = self::squash(self::render('{{ atlas_heatmap(table, "Open") }}', ['table' => self::table()]));

        self::assertStringStartsWith('<table class="tbl heat"><thead><tr><th class="sortable" data-sort="name"', $html);
        self::assertStringContainsString('<th class="num sortable" data-sort="pace" aria-sort="none" tabindex="0" role="columnheader" title="Sort by pace — days to settle">Pace<i class="sortmark"></i><span class="thtot">12<em>d</em><span class="d good">−2</span></span></th>', $html);
        self::assertStringContainsString('<tr class="pfscope"><td colspan="4"><span class="sg">Org-wide<span class="n">2</span><em>each reads every area</em></span></td></tr>', $html);
        self::assertStringContainsString('<div class="dept"><span class="mk">NO</span><div><b>North</b><span>3 positions</span></div></div>', $html);
        self::assertStringContainsString('<td class="hc" data-v="4"><div class="hcell h5" title="days to settle"><b>4<em>d</em></b><div class="r2"><span class="delta good">−1</span><svg class="spark"', $html);
        self::assertStringContainsString('<td class="hc"><div class="hcell h0 blank" title="No figure to read"><span class="mono d">no figure</span></div></td>', $html);
        self::assertStringContainsString('<div class="hcell"><div class="r2 marks"><span class="cmark good" title="on time">met</span><span class="cmark">open</span></div></div>', $html);
        self::assertStringContainsString('<td class="num perf-open"><a class="open-btn" href="/d/north">Open', $html);
    }

    public function testTheLegendSaysWhatATintIsAndIsNot(): void
    {
        $html = self::squash(self::render('{{ atlas_heat_legend(legend) }}', ['legend' => new HeatLegend(
            [new HeatLegendEntry(HeatTint::Leads, 'leads the column'), new HeatLegendEntry(HeatTint::None, 'no figure yet — not a zero')],
            'A placing inside one column.',
        )]));

        self::assertSame(
            '<div class="legend"><span><i class="sw h5"></i>leads the column</span><span><i class="sw h0"></i>no figure yet — not a zero</span><span class="d">A placing inside one column.</span></div>',
            $html,
        );
    }

    private static function table(): HeatTable
    {
        return new HeatTable(
            [new HeatColumn('pace', 'Pace', unit: 'd', caption: 'days to settle', total: '12', totalDelta: '−2', totalTone: 'good'), new HeatColumn('goals', 'Goals')],
            [new HeatBand('Org-wide', [
                new HeatRow('North', 'NO', [
                    HeatCell::figure('4', HeatTint::Leads, unit: 'd', delta: '−1', deltaTone: 'good', spark: new Sparkline([3.0, 4.0], SparkTone::Good, SparkSize::Cell), title: 'days to settle', sort: 4.0),
                    HeatCell::marks([new HeatChip('met', 'good', 'on time'), new HeatChip('open', '')]),
                ], url: '/d/north', note: '3 positions'),
                new HeatRow('South', 'SO', [HeatCell::blank('no figure', 'No figure to read'), HeatCell::blank('not its topic')]),
            ], note: 'each reads every area')],
        );
    }

    /** @param array<string, mixed> $context */
    private static function render(string $template, array $context): string
    {
        $kernel = new TestKernel('test', true);
        $kernel->boot();

        /** @var Environment $twig */
        $twig = $kernel->getContainer()->get('test.twig');
        $html = $twig->createTemplate($template)->render($context);

        $kernel->shutdown();

        return $html;
    }

    private static function squash(string $html): string
    {
        return trim((string) preg_replace(['/\s+/', '/>\s+</'], [' ', '><'], $html));
    }
}
