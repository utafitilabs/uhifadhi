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

namespace Uhifadhi\Bundle\TeamBundle\Shell;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\ShellBundle\Contract\NavigationSourceInterface;
use Uhifadhi\Bundle\ShellBundle\Model\NavItem;
use Uhifadhi\Bundle\ShellBundle\Model\NavSection;
use Uhifadhi\Bundle\TeamBundle\Access\TeamConcerns;
use Uhifadhi\Bundle\TeamBundle\Controller\PerformanceController;
use Uhifadhi\Bundle\TeamBundle\Performance\RequiredPeriod;
use Uhifadhi\Bundle\TeamBundle\Service\PerformanceTopics;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Contracts\Kpi\CurrentPeriodInterface;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Contracts\Performance\PerformanceTopicProviderInterface;
use Uhifadhi\Contracts\Shell\NavGroup;

/**
 * PERFORMANCE IN THE SIDEBAR, with its three screens under it.
 *
 * IT FILES UNDER OBSERVATORY AND NOT UNDER THE ORG CHART. Performance
 * reads every area and every department: it is a way of LOOKING at the
 * organization, which is what the Observatory heading is for, and it
 * sits beside Areas because the two are the same kind of question asked
 * from two ends.
 *
 * ITS OWN SOURCE, NOT A ROW BOLTED ONTO THE TEAM ONE. A section label
 * is a place in the sidebar rather than something a source owns — the
 * shell merges by label — so a second contributor to Observatory is the
 * documented way to add a row there, and it keeps the org chart's rows
 * and this one out of each other's file.
 *
 * THE CHILDREN ARE THE TABS. A reader who can see Briefing from the
 * sidebar does not have to open Overview to learn it exists, and the
 * strip and the tree cannot disagree because both are the same three
 * screens.
 *
 * GATED, ROUTE-TOLERANT, BUILT PER CALL — the three rules every
 * navigation source in this product keeps, and each for the reason
 * {@see TeamNavigation} states.
 */
final readonly class PerformanceNavigation implements NavigationSourceInterface
{
    /** Beside Areas, under the heading both belong to. */
    public const string SECTION = NavGroup::OBSERVATORY;

    /** After the areas tree (10) and before the org chart's rows (20). */
    public const int POSITION = 15;

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TokenStorageInterface $tokens,
        private AuthorizationCheckerInterface $authorization,
        private RequestStack $requests,
        private PerformanceTopics $topics,
        /**
         * WHAT PERIOD IT IS NOW, from whoever publishes one — rather
         * than the wall clock this read used to ask, which made the
         * answer depend on the day the page happened to be opened and
         * could not be pinned at a month boundary by any test.
         *
         * OPTIONAL IN THE CONTAINER, REQUIRED AT THE SCREEN. This
         * bundle's MODEL needs no calendar; its performance pages do.
         * A kernel taking the entities and not the pages must boot —
         * see {@see RequiredPeriod}.
         */
        private ?CurrentPeriodInterface $periods,
    ) {
    }

    public function sections(): iterable
    {
        // NO TOKEN, NO QUESTION: a page can render outside any firewall,
        // where asking the checker throws rather than answering false.
        if (null === $this->tokens->getToken()) {
            return;
        }

        if (!$this->authorization->isGranted((string) Grant::of(TeamConcerns::DIRECTORY, Verb::Manage))) {
            return;
        }

        try {
            $overview = $this->urls->generate(PerformanceController::ROUTE);
        } catch (RouteNotFoundException) {
            // An installation that unmounted the page loses the row, not
            // every page in the product.
            return;
        }

        $topics = $this->child('Topics', PerformanceController::TOPICS_ROUTE);
        $children = array_values(array_filter([
            $this->child('Overview', PerformanceController::ROUTE),
            null === $topics ? null : $this->withTopics($topics),
            $this->child('Briefing', PerformanceController::BRIEFING_ROUTE),
        ]));

        yield new NavSection(self::SECTION, [new NavItem(
            label: 'Performance',
            url: $overview,
            icon: 'shell:trending-up',
            // The section IS its three screens, the way the areas row is
            // the areas: it stays lit while none of its children is.
            current: $this->here($overview) && !self::litAnywhere($children),
            children: $children,
            // THE CHILDREN ARE SCREENS, NOT PLACES: there is no place
            // between Performance and its three tabs, so they are drawn
            // at the screen rung rather than as three places.
            screens: true,
        )], position: self::POSITION);
    }

    /**
     * TOPICS UNFOLDS TO THE TOPICS, because a reader who can see that
     * Patrols has a record does not have to open Topics to learn it —
     * and the tree and the register cannot disagree, since both are the
     * same list from the same collector.
     *
     * DRILLED ONLY WHERE IT CAN BE SEEN, the rule the areas tree and the
     * departments tree both keep: an installation with a dozen modules
     * would otherwise pay for a dozen rows on every page in the product.
     */
    private function withTopics(NavItem $topics): NavItem
    {
        $here = $this->requests->getCurrentRequest();
        $inside = null !== $here && str_starts_with($here->getBaseUrl().$here->getPathInfo(), (string) $topics->url);
        if (!$inside) {
            return $topics;
        }

        // NO PERIOD, NO SUBTREE — and the row itself stands. The topics are
        // read FOR a period, so a kernel that took this bundle's entities
        // and not its performance screens has nothing to drill; a sidebar
        // that took the whole app down to say so would be the worst
        // possible way to learn it.
        if (null === $this->periods) {
            return $topics;
        }

        $period = $this->periods->month();
        $scope = PerformanceScope::organization();

        $rows = [];
        foreach ($this->topics->forScope($scope, $period) as $topic) {
            $url = $this->urls->generate(PerformanceController::TOPIC_ROUTE, ['key' => $topic->key()]);
            $rows[] = new NavItem(
                label: $topic->title(),
                url: $url,
                current: $this->here($url),
                // A MODULE'S TOPIC WEARS THE MODULE'S OWN DOT, exactly as a
                // module row does under an area — the sidebar says the same
                // thing the register's key does.
                tone: PerformanceTopicProviderInterface::HOST === $topic->moduleSlug() ? null : $topic->moduleSlug(),
            );
        }

        return new NavItem(
            label: $topics->label,
            url: $topics->url,
            current: $topics->current && !self::litAnywhere($rows),
            children: $rows,
        );
    }

    private function child(string $label, string $route): ?NavItem
    {
        try {
            $url = $this->urls->generate($route);
        } catch (RouteNotFoundException) {
            return null;
        }

        return new NavItem(label: $label, url: $url, current: $this->here($url));
    }

    /** @param list<NavItem> $items */
    private static function litAnywhere(array $items): bool
    {
        foreach ($items as $item) {
            if ($item->current || self::litAnywhere($item->children)) {
                return true;
            }
        }

        return false;
    }

    /**
     * WHETHER THE VIEWER IS ON THIS SCREEN — compared as paths, because
     * the addresses belong to the application, and exactly rather than
     * by prefix, so Overview does not light while the reader is on
     * Topics.
     */
    private function here(string $url): bool
    {
        $request = $this->requests->getCurrentRequest();
        if (null === $request) {
            return false;
        }

        return $request->getBaseUrl().$request->getPathInfo() === $url;
    }
}
