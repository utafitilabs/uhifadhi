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

namespace Uhifadhi\Contracts\Shell;

/**
 * HOW A MODULE DECLARES WHAT IS ON ITS CONFIGURE PAGE.
 *
 * ONE CONFIGURE BUTTON, ONE CONFIGURE PAGE. Every module and the area itself
 * get exactly one configuration entry: a `Configure` page action, and one
 * shell-owned page behind it whose sections are declared here. There is no
 * Settings button, no kinds button and no Widget library button anywhere else
 * in the product, and no "Back to dashboard" — the Overview tab and the crumb
 * are the way back. Before this, every module invented its own way in and its
 * own way out, and no two agreed.
 *
 * THE SECTIONS STAND WHERE THE DATA TABS STAND. On a configure page the data
 * tabs are not shown; the section strip takes their place, same component, same
 * position. So a person always reads one strip in one place, and it always
 * answers "which of these am I looking at".
 *
 * THE ORDER IS RULED, NOT DECLARED. Widget library first, Settings last,
 * whatever a module puts between them — the collector sorts by the ids
 * {@see ConfigurationSection::WIDGETS} and {@see ConfigurationSection::SETTINGS}
 * so every configure page in the platform reads the same way round.
 *
 * THE WORDS ARE THE MODULE'S. A section's label is whatever the module calls it
 * — "Observation kinds", "Incident kinds" — and the shell prints it. The frame
 * has no vocabulary of its own to impose.
 *
 * A MODULE IS NOT AUTOCONFIGURED. A reusable bundle's services are wired
 * explicitly, so the tag goes on by hand:
 *
 *     $services->set('patrol.configuration_sections', PatrolConfigurationSections::class)
 *         ->tag(ConfigurationSectionsInterface::TAG);
 *
 * A module with no declaration has no Configure action and no configure page,
 * which is the right answer for a module with nothing to configure.
 */
interface ConfigurationSectionsInterface
{
    /**
     * The tag that puts a declaration in the frame.
     *
     * A CONSTANT, so a module spells it once and a rename is a compile error
     * rather than a configure page that quietly stops being reachable.
     */
    public const string TAG = 'uhifadhi.configuration_sections';

    /**
     * THE SLUG THE AREA ITSELF IS COLLECTED UNDER. The area configures itself
     * through this contract exactly as a module does — one frame, one page, one
     * button — and it needs a slug to be filed by.
     *
     * The underscore is what makes it safe: a module slug is lowercase letters
     * only, so no module written by anybody can ever claim this one.
     */
    public const string AREA = '_area';

    /**
     * WHOSE CONFIGURE PAGE THIS IS — a module's slug, the same one its
     * {@see \Uhifadhi\Contracts\ModuleProviderInterface::slug()} returns, or
     * {@see AREA} for the area's own.
     */
    public function slug(): string;

    /**
     * WHAT THE CONFIGURE PAGE IS CALLED, whole — "Kilimani Crater — Patrols" — to
     * which the shell adds " · configure".
     *
     * The surface composes it because only the surface knows the words: the
     * shell has a slug, not a display name, and the area it is being configured
     * in is the surface's own knowledge too. A declaration resolves the current
     * request the way every other source in the frame does.
     */
    public function heading(): string;

    /**
     * The line under the heading — what this page is for, in one sentence — or
     * null for a surface that would only repeat its own title.
     */
    public function summary(): ?string;

    /**
     * The sections of this surface's configure page, each named in the surface's
     * own words. Order them however reads best; the collector still anchors
     * Widget library first and Settings last.
     *
     * WITHHOLD A SECTION THE VIEWER MAY NOT HAVE, for the reason a tab is
     * withheld: a section they cannot open is a statement about them, not about
     * the product.
     *
     * @return list<ConfigurationSection>
     */
    public function sections(): array;
}
