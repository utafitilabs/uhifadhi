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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Permissions\CheckListing;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionAnalysisReader;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionCheckService;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionFindingsReader;
use Uhifadhi\Bundle\TeamBundle\Permissions\PowerCatalogue;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionRunRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Shell\TeamSectionTabs;
use Uhifadhi\Contracts\Access\PowerTarget;

/**
 * TEAM › PERMISSIONS › CHECK — can this person do this, here, and why?
 * Asked live of the voters, for one person, changing nothing.
 *
 * THE ADDRESS IS THE CHECK: `?person=<id>&q=<pair or power>&target=<place
 * key, "placed", or a target kind>` asks it on arrival, so every door on the
 * Permissions pages that names a person and a question lands on its answer.
 * What the address names that does not exist is left unchosen; nothing is
 * asked until a person and a question are both known.
 *
 * @phpstan-import-type Answer from CheckListing
 */
final readonly class PermissionsCheckController
{
    public function __construct(
        private Environment $twig,
        private PermissionCheckService $check,
        private PermissionAnalysisReader $analysis,
        private PermissionFindingsReader $findings,
        private PowerCatalogue $powers,
        private PermissionRunRepository $runs,
        private UserRepository $users,
        private UrlGeneratorInterface $router,
    ) {
    }

    #[Route('/team/permissions/check', name: TeamSectionTabs::CHECK, defaults: TeamController::SURFACE, methods: ['GET'])]
    #[IsGranted(PermissionsHubController::TIER)]
    public function check(Request $request): Response
    {
        $questions = $this->analysis->questions();
        $places = $this->check->places();
        $targets = [];
        foreach ($this->powers->all() as $power) {
            $targets[$power->key] = array_map(static fn (PowerTarget $t): array => ['key' => $t->value, 'label' => $t->label()], $power->targets);
        }

        $person = $this->person($request->query->get('person'));
        $asked = $request->query->get('q');
        $pairs = array_merge(...array_map(static fn (array $c): array => array_column($c['verbs'], 'pair'), $questions['concerns']));
        $q = \is_string($asked) && (\in_array($asked, $pairs, true) || isset($targets[$asked])) ? $asked : null;
        $isPower = null !== $q && isset($targets[$q]);

        $where = $request->query->get('target');
        $where = \is_string($where) ? $where : null;
        $target = $isPower
            ? (\in_array($where, array_column($targets[(string) $q], 'key'), true) ? (string) $where : ($targets[(string) $q][0]['key'] ?? ''))
            : (\in_array($where, array_column($places, 'key'), true) ? (string) $where : CheckListing::PLACED);

        $run = $this->runs->findOneLatestFinished();
        $answer = null === $person || null === $q ? null : $this->answer($person, $q, $target, $isPower, null === $run ? [] : array_column(PermissionAnalysisReader::tiers($run), 'label'));

        return new Response($this->twig->render('@Team/permissions/check.html.twig', CheckListing::build(
            [
                'people' => $this->check->people(),
                'questions' => $questions,
                'places' => $places,
                'targets' => $targets,
                'person' => null === $person ? null : (int) $person->getId(),
                'q' => $q,
                'target' => $target,
            ],
            $answer,
            ['rows' => $this->findings->worthRunning(), 'asOf' => $run?->getStartedAt()],
            $this->router->generate(TeamSectionTabs::CHECK),
        )));
    }

    /**
     * @param list<string> $tiers
     *
     * @return Answer
     */
    private function answer(User $person, string $q, string $target, bool $isPower, array $tiers): array
    {
        $place = $isPower ? $this->check->ownPlace($person) : (CheckListing::PLACED === $target ? $this->check->ownPlace($person) : null);
        $check = $isPower ? $this->check->checkPower($person, $q, $target) : $this->check->checkPair($person, $q, $place['key'] ?? $target);
        $place ??= $check['place'] ?? $this->check->ownPlace($person);
        $lens = $this->check->atPlace($person, $q, $place['key']);

        return [
            'person' => ['id' => (int) $person->getId(), 'name' => $lens['person']],
            'q' => $q,
            'target' => $target,
            'place' => $place,
            'check' => [
                'allowed' => $check['allowed'],
                'lines' => array_map(fn (array $l): array => [...$l, 'url' => $this->door($l['door'])], $check['lines']),
                'coverage' => $check['coverage'],
            ],
            'lens' => $lens,
            'aboutPerson' => $this->check->aboutPerson($person),
            'aboutQuestion' => $this->check->aboutQuestion($q, $isPower ? $target : null),
            'tiers' => $tiers,
            'actors' => $isPower ? \count($this->analysis->forPower($q)['rows']) : 0,
            'placementUrl' => $this->router->generate('team_member', ['uuid' => $person->getUuidString()]),
        ];
    }

    /** @param array{kind: string, id: int|null, uuid: string|null, name: string}|null $door */
    private function door(?array $door): ?string
    {
        if (null === $door || null === $door['uuid']) {
            return null;
        }

        return match ($door['kind']) {
            'person', 'placement' => $this->router->generate('team_member', ['uuid' => $door['uuid']]),
            'position', 'exception' => $this->router->generate('team_position_show', ['uuid' => $door['uuid']]),
            'department' => $this->router->generate(DepartmentController::RECORD, ['uuid' => $door['uuid']]),
            default => null,
        };
    }

    private function person(mixed $id): ?User
    {
        if (!\is_string($id) || !ctype_digit($id)) {
            return null;
        }
        $person = $this->users->find((int) $id);

        return $person instanceof User && $person->isActive() ? $person : null;
    }
}
