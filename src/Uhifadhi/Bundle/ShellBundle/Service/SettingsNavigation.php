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

namespace Uhifadhi\Bundle\ShellBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\ShellBundle\Access\ShellConcerns;
use Uhifadhi\Bundle\ShellBundle\Contract\NavigationSourceInterface;
use Uhifadhi\Bundle\ShellBundle\Model\NavItem;
use Uhifadhi\Bundle\ShellBundle\Model\NavSection;
use Uhifadhi\Contracts\Settings\SettingsTab;
use Uhifadhi\Contracts\Shell\NavGroup;

/**
 * THE SETTINGS GROUP: ONE ROW, WITH THE SECTION'S SCREENS UNDER IT.
 *
 * A CATEGORY OF ITS OWN, AND IT COMES LAST (ruled 2026-09-20). Settings was a
 * foot item pushed to the bottom of the sidebar; the owner ruled it a group
 * instead, which is why {@see NavGroup::SETTINGS} exists and why the foot is
 * gone — it held nothing else.
 *
 * ONE ITEM IN THE GROUP, because the four screens are one page's tabs and not
 * four rows. `screens: true` says exactly that: there is no place rung between
 * the row and its children, which is the shape an area and the files section
 * already wear.
 *
 * IT DOES NOT DECIDE WHAT IS OPEN. Which rung is lit and which subtree is
 * unfolded is DERIVED by the shell from the row marked `current` — one
 * ancestor path, one ground per tree — so this source marks where the viewer
 * is and passes no `open` at all.
 *
 * THE SAME DECLARATION THE TAB STRIP IS BUILT FROM. Both come off
 * {@see SettingsTab} through {@see SettingsSection}, so a tab and a sidebar
 * child cannot disagree about which screens the section has.
 *
 * ROUTE-TOLERANT. The address belongs to the APPLICATION, which imports the
 * section's route resource or does not; an installation that has not mounted
 * it gets no row rather than a sidebar that takes every page down with it.
 *
 * ONLY FOR WHOEVER HOLDS THE SECTION'S PAIR. Gating is a source's job, not
 * the renderer's, and this is the section's source: a viewer without
 * {@see ShellConcerns::SETTINGS_READ} gets no row, rather than a row whose
 * address refuses them. A kernel with no security at all has nobody to ask
 * and keeps the row, behind whatever its firewall puts the section.
 *
 * BUILT PER CALL, NEVER CACHED — the shell reads its sources live on every
 * render, and nothing here happens in the constructor.
 */
final readonly class SettingsNavigation implements NavigationSourceInterface
{
    /** The group, and it is the last of the four. */
    public const string SECTION = NavGroup::SETTINGS;

    public function __construct(
        private SettingsSection $section,
        private RequestStack $requests,
        private ?AuthorizationCheckerInterface $authorization = null,
        private ?TokenStorageInterface $tokens = null,
    ) {
    }

    public function sections(): iterable
    {
        if (null !== $this->authorization) {
            // NO TOKEN, NO QUESTION: outside a firewall the checker throws
            // rather than answering, and nobody identified holds nothing.
            if (null === $this->tokens?->getToken() || !$this->authorization->isGranted(ShellConcerns::SETTINGS_READ)) {
                return;
            }
        }

        try {
            $children = [];
            foreach (SettingsTab::cases() as $tab) {
                if (!$this->section->shows($tab)) {
                    continue;
                }
                $address = $this->section->addressOf($tab);
                $children[] = new NavItem(
                    label: $tab->label(),
                    url: $address,
                    current: $this->viewerIsAt($address),
                );
            }
        } catch (RouteNotFoundException) {
            return;
        }

        if ([] === $children) {
            return;
        }

        yield new NavSection(self::SECTION, [new NavItem(
            label: SettingsSection::TITLE,
            // THE ROW OPENS ON THE FIRST SCREEN, which is the bare address —
            // the same one the section's own name means everywhere else.
            url: $children[0]->url,
            icon: 'shell:settings',
            // MARKED ANYWHERE INSIDE THE SECTION, and the child says which
            // screen. One path; the shell turns it into one ground and a
            // line of ink.
            current: [] !== array_filter($children, static fn (NavItem $child): bool => $child->current),
            children: $children,
            screens: true,
        )]);
    }

    /**
     * EXACTLY HERE, NOT UNDER HERE, and the difference is the whole of the
     * reason this is not the prefix comparison the other sources use: the
     * first screen's address is the PREFIX of every other screen's, so a
     * prefix match would light it on all four and the sidebar would say the
     * viewer was in two places at once.
     */
    private function viewerIsAt(string $address): bool
    {
        $request = $this->requests->getCurrentRequest();
        if (null === $request) {
            return false;
        }

        return $request->getBaseUrl().$request->getPathInfo() === $address;
    }
}
