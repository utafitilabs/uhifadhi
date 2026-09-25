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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Template;

use Uhifadhi\Bundle\AtlasBundle\AtlasBundle;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\ShellBundle\Test\VocabularyConformanceTestCase;

/**
 * THE TEAM SCREENS SPEND NOTHING NOBODY SHIPS.
 *
 * THE CHAIN IS THE SHEETS A PAGE ACTUALLY LINKS. `_stylesheets.html.twig` links
 * the frame's sheet, then the widget library's — the roster and the matrix are
 * both drawn as widget canvases — and then this bundle's own, which is last
 * because it is the one allowed to decorate.
 */
final class VocabularyConformanceTest extends VocabularyConformanceTestCase
{
    protected static function bundlePath(): string
    {
        return \dirname(__DIR__, 3);
    }

    protected static function alias(): string
    {
        return 'team';
    }

    protected static function ownStylesheets(): array
    {
        return ['team.css', 'performance.css'];
    }

    /**
     * THE ATLAS'S SHEETS ARE IN EVERY HEAD — published to the shell through
     * AtlasStylesheets — so a class an atlas component draws on a team page
     * is shipped by the chain the page actually loads.
     */
    protected static function linkedStylesheets(): array
    {
        $shell = \dirname(new \ReflectionClass(ShellBundle::class)->getFileName() ?: '').'/public';
        $atlas = \dirname(new \ReflectionClass(AtlasBundle::class)->getFileName() ?: '').'/public';

        return [$shell.'/shell.css', $shell.'/widget.css', $atlas.'/map.css', $atlas.'/chart.css', $atlas.'/calendar.css', $atlas.'/heat.css'];
    }

    /**
     * NOT EXEMPT ANY MORE, and the debt it stood for is paid.
     *
     * `team.css` carried module identity hues — a roster blue kept in two
     * values because the invented one was illegible on paper, an incidents
     * pink, an incidents red — which the "modules have no hue" ruling
     * refuses: a module is the accent or it is the host's muted dot, and the
     * module's NAME is what says which module it is. The one literal left
     * was the white on a danger button's fill, and that is a token now
     * (`--c-failT`).
     */
    protected static function declaresItsOwnPalette(): bool
    {
        return false;
    }
}
