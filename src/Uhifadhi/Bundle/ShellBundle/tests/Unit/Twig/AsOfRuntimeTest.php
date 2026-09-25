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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Unit\Twig;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Uhifadhi\Bundle\ShellBundle\Twig\AsOfRuntime;
use Uhifadhi\Bundle\ShellBundle\Twig\ShellExtension;
use Uhifadhi\Contracts\Facts\Fact;
use Uhifadhi\Contracts\Facts\FactSubject;

/**
 * `shell_as_of()` — THE TIME A STORED FIGURE IS TRUE AS OF, beside the figure.
 *
 * A figure read from the facts ledger was computed by the worker, not for
 * this request; the page says when, as the house fragment "as of 13:00",
 * through the shell's own `<time>` idiom so the reader's zone is the one
 * printed. A figure nobody has computed yet says so and shows no number;
 * a figure whose period has closed is final and needs no time.
 */
#[CoversClass(AsOfRuntime::class)]
final class AsOfRuntimeTest extends TestCase
{
    private const string AREA = '0199a1a0-0000-7000-8000-00000000000a';

    public function testAFigureComputedTodayIsAsOfItsClock(): void
    {
        $html = $this->render($this->fact('2026-09', '2026-09-25 13:00:00'));

        self::assertSame(
            '<span class="muted">as of <time datetime="2026-09-25T13:00:00+00:00" data-localtime-format="clock">13:00</time></span>',
            $html,
        );
    }

    /** Older than a day, the clock alone would read as today's: the fragment carries the day. */
    public function testAnOlderFigureCarriesItsDay(): void
    {
        $html = $this->render($this->fact('2026-09', '2026-09-23 20:00:00'));

        self::assertSame(
            '<span class="muted">as of <time datetime="2026-09-23T20:00:00+00:00" data-localtime-format="stamp">23 sep · 20:00</time></span>',
            $html,
        );
    }

    public function testAFigureNobodyComputedSaysSo(): void
    {
        self::assertSame('<span class="muted">not computed yet</span>', $this->render(null));
    }

    /** A closed period's final figure is the period's figure, with no time beside it. */
    public function testAFinalFigureNeedsNoTime(): void
    {
        self::assertSame('', $this->render($this->fact('2026-08', '2026-09-01 02:00:00')));
    }

    /** An instant is accepted as it is, for a figure that carries its time rather than its fact. */
    public function testAnInstantIsAsOfItself(): void
    {
        self::assertStringContainsString(
            'data-localtime-format="clock">13:00</time>',
            $this->render(new \DateTimeImmutable('2026-09-25 13:00:00', new \DateTimeZone('UTC'))),
        );
    }

    private function fact(string $period, string $computedAt): Fact
    {
        return new Fact(FactSubject::AREA, self::AREA, 'surveys.share', $period, 0.5, new \DateTimeImmutable($computedAt, new \DateTimeZone('UTC')));
    }

    private function render(Fact|\DateTimeImmutable|null $subject): string
    {
        $filesystem = new FilesystemLoader();
        $filesystem->addPath(\dirname(__DIR__, 3).'/templates', 'Shell');

        $twig = new Environment(new ChainLoader([new ArrayLoader(['page' => '{{ shell_as_of(subject) }}']), $filesystem]), [
            'strict_variables' => true,
        ]);
        $twig->addExtension(new ShellExtension());
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            AsOfRuntime::class => static fn (): AsOfRuntime => new AsOfRuntime(new MockClock('2026-09-25 14:00:00', 'UTC')),
        ]));

        return $twig->render('page', ['subject' => $subject]);
    }
}
