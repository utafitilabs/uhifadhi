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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\TestKernel;

/**
 * THE ATTENTION BANNER WITH SEVERAL LINES — the house banner the Departments
 * and Performance pages carry above their content: one icon, then one line a
 * thing to act on, its head, its cause and its door; nothing when there is
 * nothing to act on.
 */
final class AttentionBannerTest extends TestCase
{
    public function testEveryLineIsItsHeadItsCauseAndItsDoor(): void
    {
        $banner = self::render('Needs a decision', [
            ['head' => 'Analyst has stood empty 96 days', 'cause' => 'Protection Service · Fill the post, or close it', 'door' => 'Open', 'url' => '/departments/performance/topics/staffing'],
            ['head' => '2 departments read no module', 'cause' => 'Planning, Engineering'],
        ])->filter('.banner');

        self::assertCount(1, $banner);
        self::assertSame('region', $banner->attr('role'));
        self::assertSame('Needs a decision', $banner->attr('aria-label'));
        self::assertCount(1, $banner->filter('svg'), 'one icon for the whole banner');

        $lines = $banner->filter('.banner-l > li');
        self::assertCount(2, $lines);
        self::assertSame('Analyst has stood empty 96 days', $lines->eq(0)->filter('.banner-t b')->text());
        self::assertSame('— Protection Service · Fill the post, or close it', $lines->eq(0)->filter('.banner-c')->text());
        self::assertSame('/departments/performance/topics/staffing', $lines->eq(0)->filter('.banner-acts a.btn.sm')->attr('href'));
        self::assertCount(0, $lines->eq(1)->filter('.banner-acts'), 'a line with no door draws none');
    }

    public function testNothingToActOnDrawsNoBanner(): void
    {
        self::assertCount(0, self::render('Needs attention', [])->filter('.banner'));
    }

    /** @param list<array<string, string>> $lines */
    private static function render(string $label, array $lines): Crawler
    {
        $kernel = new TestKernel('test', true);
        $kernel->boot();

        /** @var Environment $twig */
        $twig = $kernel->getContainer()->get('test.twig');
        $html = $twig->createTemplate("{% import '@Team/departments/_bits.html.twig' as di %}<div>{{ di.attention(label, lines) }}</div>")->render([
            'label' => $label,
            'lines' => $lines,
        ]);

        $kernel->shutdown();

        return new Crawler($html);
    }
}
