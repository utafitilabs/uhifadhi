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

namespace Uhifadhi\Bundle\TeamBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Performance\AcrossTopicsMatrix;
use Uhifadhi\Bundle\TeamBundle\Performance\ChartBridge;
use Uhifadhi\Bundle\TeamBundle\Performance\Comparison;
use Uhifadhi\Bundle\TeamBundle\Performance\GoalsTopic;
use Uhifadhi\Bundle\TeamBundle\Performance\OrganizationBand;
use Uhifadhi\Bundle\TeamBundle\Performance\PeriodKind;
use Uhifadhi\Bundle\TeamBundle\Performance\RequiredPeriod;
use Uhifadhi\Bundle\TeamBundle\Performance\TopicCard;
use Uhifadhi\Bundle\TeamBundle\Performance\TopicCards;
use Uhifadhi\Bundle\TeamBundle\Service\PerformanceTopics;
use Uhifadhi\Bundle\TeamBundle\Shell\PerformanceSectionTabs;
use Uhifadhi\Contracts\Entity\AreaInterface;
use Uhifadhi\Contracts\Kpi\CurrentPeriodInterface;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\DepartmentDirectoryInterface;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Contracts\Performance\PerformanceTopicProviderInterface;
use Uhifadhi\Contracts\Performance\TopicChart;
use Uhifadhi\Contracts\Performance\TopicDecisionsInterface;
use Uhifadhi\Contracts\Performance\TopicMovementInterface;

/**
 * PERFORMANCE — the organization's own surface, wearing the area idiom.
 *
 * THREE TABS AND ONE SUBJECT. Overview is what changed across every
 * topic; Topics is one topic at a time, each a whole record; Briefing is
 * what needs a decision. The strip is the same strip an area wears,
 * because a reader who has learnt one section of this product has learnt
 * this one.
 *
 * SCOPE AND PERIOD ARE IN THE ADDRESS, never in a session. A director
 * reading the organization's August and an area manager reading
 * Northreach's quarter are looking at two pages, and either can be sent
 * to somebody — which a control that remembered its last state could not
 * be.
 *
 * THE PAGE COMPUTES NOTHING ITSELF. Every figure on it is published by a
 * topic through the performance seam, and the one decision the page
 * makes about somebody else's figures — where a department stands — is
 * made once, in {@see \Uhifadhi\Bundle\TeamBundle\Performance\MatrixPlacing}.
 *
 * GATED ON `departments.read`, the same pair the departments register is
 * gated on: this page reads every department's figures, so it is the
 * org chart's surface and not a public one.
 */
final readonly class PerformanceController
{
    public const string ROUTE = 'team_performance';
    public const string TOPICS_ROUTE = 'team_performance_topics';
    public const string TOPIC_ROUTE = 'team_performance_topic';
    public const string BRIEFING_ROUTE = 'team_performance_briefing';

    /**
     * THE SURFACE MARKER — the route default that tells the shell this
     * page is a screen of the performance section, so the frame draws
     * the strip and the one Configure action rather than the page
     * building either for itself.
     */
    public const array SURFACE = ['_uhifadhi_module' => PerformanceSectionTabs::SURFACE];

    /** How many of the raised decisions the briefing prints before it counts the rest. */
    public const int DECISIONS_SHOWN = 5;

    /** What the scope picker calls the organization, on the page and in the picker. */
    public const string ORGANIZATION = 'Organization — all areas';

    public function __construct(
        private Environment $twig,
        private PerformanceTopics $topics,
        private DepartmentDirectoryInterface $directory,
        private AcrossTopicsMatrix $across,
        private TopicCards $cards,
        private OrganizationBand $band,
        private UrlGeneratorInterface $urls,
        private EntityManagerInterface $entityManager,
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

    #[Route('/departments/performance', name: self::ROUTE, defaults: self::SURFACE, methods: ['GET'])]
    #[IsGranted('departments.read')]
    public function overview(Request $request): Response
    {
        [$kind, $compare, $period] = $this->reading($request);
        $scope = $this->scope($request);

        $topics = $this->topics->forScope($scope, $period);

        return new Response($this->twig->render('@Team/performance/overview.html.twig', [
            ...$this->frame($scope, $period, $kind, $compare, self::ROUTE),
            'cards' => $this->cards->strip(
                $topics,
                $scope,
                $period,
                fn (string $key): string => $this->urls->generate(self::TOPIC_ROUTE, [
                    'key' => $key,
                    'period' => $kind->value,
                    'area' => $scope->areaUuid,
                ]),
            ),
            'matrix' => $this->across->build(
                $topics,
                $this->directory->forScope($scope),
                $scope,
                $period,
                fn (string $uuid): string => $this->urls->generate('team_department_show', ['uuid' => $uuid]),
            ),
        ]));
    }

    /**
     * ONE TOPIC AT A TIME — the register of topic records.
     *
     * AN INDEX AND NOT AN ACCORDION. Ruled 2026-09-20: a card answers
     * "is there anything here for me this period" in one line, and the
     * record behind it answers the topic. Five topics stacked on one
     * page would be five pages nobody scrolls to the bottom of.
     */
    #[Route('/departments/performance/topics', name: self::TOPICS_ROUTE, defaults: self::SURFACE, methods: ['GET'])]
    #[IsGranted('departments.read')]
    public function topics(Request $request): Response
    {
        [$kind, $compare, $period] = $this->reading($request);
        $scope = $this->scope($request);

        $topics = $this->topics->forScope($scope, $period);
        $cards = $this->cards->build($topics, $scope, $period, fn (string $key): string => $this->urls->generate(
            self::TOPIC_ROUTE,
            ['key' => $key, 'period' => $kind->value, 'area' => $scope->areaUuid],
        ));

        return new Response($this->twig->render('@Team/performance/topics.html.twig', [
            ...$this->frame($scope, $period, $kind, $compare, self::TOPICS_ROUTE),
            'cards' => $cards,
            // HOW MANY OF THEM ARE THE HOST'S, said once above the grid —
            // the same distinction the key below it explains, counted.
            'byModule' => \count(array_filter($cards, static fn (TopicCard $card): bool => $card->byModule)),
        ]));
    }

    /**
     * ONE TOPIC'S RECORD: its five figures, its charts, and the matrix
     * of the departments it applies to.
     *
     * THE PAGE IS THE SAME FOR EVERY TOPIC, host's and module's alike.
     * A topic publishes five KPIs, two or three charts and a matrix, and
     * this draws exactly those — so a module that publishes a topic gets
     * a record the day it is installed and the host writes nothing.
     */
    #[Route('/departments/performance/topics/{key}', name: self::TOPIC_ROUTE, requirements: ['key' => '[a-z0-9_.-]+'], methods: ['GET'])]
    #[IsGranted('departments.read')]
    public function topic(Request $request, string $key): Response
    {
        [$kind, $compare, $period] = $this->reading($request);
        $scope = $this->scope($request);

        $topic = $this->topics->byKey($key, $scope, $period);
        if (null === $topic) {
            // A TOPIC THIS INSTALLATION DOES NOT CARRY is not an empty
            // page: the module was switched off, or never installed, and
            // the address means nothing here.
            throw new NotFoundHttpException(\sprintf('No topic answers to "%s" in this installation.', $key));
        }

        return new Response($this->twig->render('@Team/performance/topic.html.twig', [
            ...$this->frame($scope, $period, $kind, $compare, self::TOPICS_ROUTE),
            'topic' => $topic->title(),
            'byModule' => PerformanceTopicProviderInterface::HOST !== $topic->moduleSlug(),
            'kpis' => $topic->kpis($scope, $period),
            'charts' => array_map(
                static fn (TopicChart $chart): array => [
                    'chart' => ChartBridge::atlas($chart),
                    'title' => $chart->title,
                    'caption' => $chart->caption,
                ],
                $topic->charts($scope, $period),
            ),
            'matrix' => $topic->matrix($scope, $period),
            'publisher' => PerformanceTopicProviderInterface::HOST === $topic->moduleSlug()
                ? 'the host'
                : $topic->moduleSlug().' module',
        ]));
    }

    /**
     * WHAT CHANGED, AND WHAT TO DECIDE — the third screen, and the one
     * a director opens first.
     *
     * IT ADDS UP NOTHING OF ITS OWN. Every line on it is published: the
     * band is the Goals topic's own five figures, "what changed" is one
     * line per topic that can write one
     * ({@see TopicMovementInterface}),
     * and the ledger is the Goals topic's matrix drawn in the one
     * grammar every matrix is drawn in.
     */
    #[Route('/departments/performance/briefing', name: self::BRIEFING_ROUTE, defaults: self::SURFACE, methods: ['GET'])]
    #[IsGranted('departments.read')]
    public function briefing(Request $request): Response
    {
        [$kind, $compare, $period] = $this->reading($request);
        $scope = $this->scope($request);

        $topics = $this->topics->forScope($scope, $period);

        $moved = [];
        $decisions = [];
        $raised = 0;
        foreach ($topics as $topic) {
            if ($topic instanceof TopicDecisionsInterface) {
                $own = $topic->decisions($scope, $period);
                $raised += \count($own);
                foreach ($own as $decision) {
                    $decisions[] = ['topic' => $topic->title(), 'decision' => $decision, 'url' => $this->urls->generate(
                        self::TOPIC_ROUTE,
                        ['key' => $topic->key(), 'period' => $kind->value, 'area' => $scope->areaUuid],
                    )];
                }
            }

            // A TOPIC THAT CANNOT WRITE A SENTENCE IS NOT ASKED, and one
            // with nothing worth saying answers null — both are silence
            // rather than an empty row.
            $movement = $topic instanceof TopicMovementInterface ? $topic->movement($scope, $period) : null;
            if (null !== $movement) {
                $moved[] = ['topic' => $topic->title(), 'movement' => $movement, 'url' => $this->urls->generate(
                    self::TOPIC_ROUTE,
                    ['key' => $topic->key(), 'period' => $kind->value, 'area' => $scope->areaUuid],
                )];
            }
        }

        $goals = $this->topics->byKey(GoalsTopic::KEY, $scope, $period);

        return new Response($this->twig->render('@Team/performance/briefing.html.twig', [
            ...$this->frame($scope, $period, $kind, $compare, self::BRIEFING_ROUTE),
            // THE BAND IS THE GOALS TOPIC'S OWN FIVE, by the keys it
            // published them under — a briefing that summed them itself
            // would be a second answer to a question already answered.
            'goals' => $goals?->kpis($scope, $period) ?? [],
            'ledger' => $goals?->matrix($scope, $period),
            'moved' => $moved,
            // THE FIRST FEW, AND HOW MANY THERE ARE IN ALL. "5 of 12" is
            // a decision about attention, and it is the page's to make —
            // a card that listed twelve would be a list nobody reads to
            // the end of.
            'decisions' => \array_slice($decisions, 0, self::DECISIONS_SHOWN),
            'raised' => $raised,
        ]));
    }

    /**
     * WHAT THE ADDRESS SAYS THIS PAGE IS ABOUT: which window, what it
     * is read against, and the period the two work out to.
     *
     * ONE READING FOR THE WHOLE SECTION, because a comparison that
     * meant one thing on the Overview and another on a record would
     * make "up 3" two different claims on one page.
     *
     * @return array{PeriodKind, Comparison, FigurePeriod}
     */
    private function reading(Request $request): array
    {
        $kind = PeriodKind::fromRequest($request->query->getString('period'));
        $compare = Comparison::fromRequest($request->query->getString('compare'));
        $period = $kind->period(RequiredPeriod::of($this->periods)->now());

        return [$kind, $compare, $period->comparedWith($compare->of($period))];
    }

    /**
     * WHAT EVERY SCREEN IN THIS SECTION CARRIES: the scope and the
     * period in the action row, the strip of sibling screens, and the
     * subline that names all three. Composed once, because a reader
     * moving between them is moving screen and never subject.
     *
     * @return array<string, mixed>
     */
    private function frame(PerformanceScope $scope, FigurePeriod $period, PeriodKind $kind, Comparison $compare, string $current): array
    {
        return [
            // THE SAME SIX ON EVERY SCREEN OF THE SECTION, picked by key
            // from the host's own three topics — a module never changes
            // the organization's own band.
            'band' => $this->band->build($this->topics->forScope($scope, $period), $scope, $period),
            'scope' => $scope,
            'organization' => self::ORGANIZATION,
            'areas' => $this->areas(),
            'period' => $period,
            // WHAT THE SUBLINE NAMES is what the figures were actually
            // read against — the two cannot disagree, because they are
            // the same object.
            'previous' => $period->against(),
            'kind' => $kind,
            'kinds' => PeriodKind::labels(),
            'urls' => $this->periodUrls($scope, $compare),
            'compare' => $compare,
            'comparisons' => $this->comparisons($scope, $period, $kind, $compare),
        ];
    }

    /**
     * THE COMPARISONS ON OFFER, each an address — the same rule the
     * scope and the period keep, so a reader can send the page they are
     * looking at to somebody.
     *
     * @return list<array{label: string, url: string, on: bool}>
     */
    private function comparisons(PerformanceScope $scope, FigurePeriod $period, PeriodKind $kind, Comparison $chosen): array
    {
        $offered = [];
        foreach (Comparison::cases() as $case) {
            $offered[] = [
                'label' => $case->label($period),
                'url' => $this->urls->generate(self::ROUTE, [
                    'period' => $kind->value,
                    'area' => $scope->areaUuid,
                    'compare' => $case->value,
                ]),
                'on' => $case === $chosen,
            ];
        }

        return $offered;
    }

    /**
     * WHERE EACH SEGMENT OF THE PERIOD GROUP GOES, carrying the scope
     * with it: changing the window is not changing whose figures they
     * are.
     *
     * @return array<string, string>
     */
    private function periodUrls(PerformanceScope $scope, Comparison $compare): array
    {
        $urls = [];
        foreach (PeriodKind::cases() as $kind) {
            $urls[$kind->value] = $this->urls->generate(self::ROUTE, [
                'period' => $kind->value,
                'area' => $scope->areaUuid,
                'compare' => $compare->value,
            ]);
        }

        return $urls;
    }

    /** The organization, or the one area the address names. */
    private function scope(Request $request): PerformanceScope
    {
        $uuid = $request->query->getString('area');
        if ('' === $uuid) {
            return PerformanceScope::organization(self::ORGANIZATION);
        }

        foreach ($this->areas() as $area) {
            if ($area->getUuidString() === $uuid) {
                return PerformanceScope::area($uuid, (string) $area->getName());
            }
        }

        // AN ADDRESS NAMING NO AREA IS THE ORGANIZATION, not a 404: a
        // stale link to an area somebody wound down should open the page
        // it was sent from rather than a wall.
        return PerformanceScope::organization(self::ORGANIZATION);
    }

    /**
     * The installation's areas, reached through the contract exactly as
     * the departments register reaches them — this bundle points at an
     * area and requires no area package to do it.
     *
     * @return list<AreaInterface>
     */
    private function areas(): array
    {
        $class = $this->entityManager->getClassMetadata(Department::class)->getAssociationTargetClass('area');

        /** @var list<AreaInterface> $areas */
        $areas = $this->entityManager->getRepository($class)->findBy([], ['name' => 'ASC']);

        return $areas;
    }
}
