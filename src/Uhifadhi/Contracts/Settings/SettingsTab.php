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

namespace Uhifadhi\Contracts\Settings;

/**
 * THE SETTINGS SECTION'S SCREENS, IN THE ORDER THEY ARE READ.
 *
 * SETTINGS WEARS THE AREA IDIOM (ruled 2026-09-20), which means an overview
 * first, then the screens the section owns, and the one that EDITS the
 * organization last. The order is published here, once, because two things
 * read it — the tab strip on the page and the section's subtree in the
 * sidebar — and a strip and a sidebar row that disagreed about which screens
 * a section has would each be evidence against the other.
 *
 * THE VALUE IS THE ADDRESS AND THE LABEL IS THE WORD. `/settings` is the
 * first screen and takes no segment of its own; every other screen hangs one
 * segment below it, which is the same shape a configure page's sections wear.
 *
 * WHY THIS IS PUBLISHED RATHER THAN PRIVATE TO THE SHELL. It is the same kind
 * of fact as {@see \Uhifadhi\Contracts\Shell\NavGroup}: a PLACE in the
 * product, named so that whoever draws it joins by constant rather than by
 * retyping a string. Nothing outside implements it — a module has no reason
 * to add a Settings screen, because what lives there is true of the whole
 * installation and a module's settings are on that module's own Configure
 * page.
 */
enum SettingsTab: string
{
    /** What this installation gives you, what it runs, and what is left to set up. */
    case Overview = 'overview';

    /** What is installed, at what version, where it runs, and its health. */
    case Installation = 'installation';

    /** The catalogue: every module the installation can run, and who runs it. */
    case Modules = 'modules';

    /** Who the installation belongs to. It edits, so it comes last. */
    case Organization = 'organization';

    /**
     * The first screen — the one the sidebar row and the section's own name
     * open on, and the one drawn at the bare address.
     */
    public static function first(): self
    {
        return self::cases()[0];
    }

    /** Whether this is the screen at `/settings` itself. */
    public function isFirst(): bool
    {
        return $this === self::first();
    }

    /** What the tab says. */
    public function label(): string
    {
        return match ($this) {
            self::Overview => 'Overview',
            self::Installation => 'Installation',
            self::Modules => 'Modules',
            self::Organization => 'Organization',
        };
    }

    /**
     * The screen's own subline — the section's head is the same on every tab
     * and this is the line under it that says what THIS screen answers. The
     * area tab header rule, one section up.
     */
    public function subtitle(): string
    {
        return match ($this) {
            self::Overview => 'What this installation gives you, what it runs, and who it belongs to. Organization scope — an area’s own settings are on the area, and a module’s are on that module’s Configure page.',
            self::Installation => 'What is installed, at what version, which areas run it, and whether any of it needs attention. This is the page that used to be the front door.',
            self::Modules => 'The catalogue: every module this installation can run, what it does, and which areas run it.',
            self::Organization => 'Who this installation belongs to: the name it is known by, its mark, and where in the world it is.',
        };
    }

    /**
     * The address segment below `/settings`, or null for the first screen,
     * which is the bare address.
     */
    public function segment(): ?string
    {
        return $this->isFirst() ? null : $this->value;
    }
}
