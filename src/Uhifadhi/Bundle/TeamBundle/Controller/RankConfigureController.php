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

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\TeamBundle\Access\TeamConcerns;
use Uhifadhi\Bundle\TeamBundle\Entity\Rank;
use Uhifadhi\Bundle\TeamBundle\Entity\RankScale;
use Uhifadhi\Bundle\TeamBundle\Repository\RankHoldingRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\RankRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\RankScaleRepository;
use Uhifadhi\Bundle\TeamBundle\Service\RankService;
use Uhifadhi\Bundle\TeamBundle\Service\TeamSettingsService;
use Uhifadhi\Contracts\Access\Verb;

/**
 * TEAM CONFIGURE › RANKS — the fourth section, mirroring the Ranks sub-page.
 *
 * TWO KINDS OF CARD. The switch first ("this organization uses ranks", on by
 * default, for the whole organization); then one card per scale, each an
 * ordered form of its ranks with an add row, saved as one write. With one
 * scale the card carries no scale vocabulary and a soft door under the list
 * offers a second; with several, each card is named and a create card adds
 * another. Scales are the organization's and never a department's.
 *
 * THE SECTION STAYS REACHABLE WITH RANKS OFF: the switch that turns them back
 * on lives here.
 */
final readonly class RankConfigureController
{
    public const string SECTION = 'team_configure_ranks';
    public const string CONFIGURE = TeamConcerns::RANKS.'.'.Verb::Configure->value;
    public const string CSRF_ID = 'team_ranks';

    public function __construct(
        private Environment $twig,
        private RankService $ranks,
        private RankScaleRepository $scales,
        private RankRepository $rankRepository,
        private RankHoldingRepository $holdings,
        private TeamSettingsService $settings,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $router,
    ) {
    }

    #[Route('/team/configure/ranks', name: self::SECTION, defaults: TeamController::SURFACE, methods: ['GET'])]
    #[IsGranted(self::CONFIGURE)]
    public function show(Request $request): Response
    {
        $scales = $this->scales->findAllOrdered();
        if ([] === $scales) {
            $scales = [$this->ranks->defaultScale()];
        }

        $cards = [];
        foreach ($scales as $scale) {
            $ranks = $this->rankRepository->findActiveByScale($scale);
            $cards[] = [
                'scale' => $scale,
                'ranks' => $ranks,
                // The other scales a row's arrows move a rank to, and whether
                // the card's own Remove door is awake: only once nothing live
                // is on the scale, and never for the last scale.
                'others' => array_values(array_filter($scales, static fn (RankScale $s): bool => $s !== $scale)),
                'removable' => [] === $ranks && \count($scales) > 1,
            ];
        }

        return new Response($this->twig->render('@Team/team/configure/ranks.html.twig', [
            'usesRanks' => $this->settings->current()->usesRanks(),
            'cards' => $cards,
            'several' => \count($scales) > 1,
            'addingScale' => \count($scales) > 1 || 'scale' === $request->query->get('add'),
            'firstUnnamed' => null === $scales[0]->getName(),
            'holders' => $this->holdings->countCurrentByRank(),
            'csrfToken' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]));
    }

    /** THE SWITCH, SAVED — the one write of the first card. */
    #[Route('/team/configure/ranks/switch', name: 'team_configure_ranks_switch', methods: ['POST'])]
    #[IsGranted(self::CONFIGURE)]
    public function saveSwitch(Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $this->settings->setUsesRanks('off' !== $request->request->get('usesRanks'));

        return $this->back($request, null, 'Saved. Applies across the organization.');
    }

    /** ONE SCALE'S CARD, SAVED AS ONE WRITE: its name, its rows in the order posted, and the add row. */
    #[Route('/team/configure/ranks/{uuid}', name: 'team_configure_ranks_save', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::CONFIGURE)]
    public function saveScale(Request $request, string $uuid): RedirectResponse
    {
        $this->assertCsrf($request);
        $scale = $this->scales->findOneBy(['uuid' => $uuid]) ?? throw new NotFoundHttpException('No such scale.');

        $rows = [];
        $posted = $request->request->all('ranks');
        foreach ($posted as $rankUuid => $row) {
            if (\is_array($row)) {
                $rows[(string) $rankUuid] = ['name' => self::text($row['name'] ?? null), 'code' => self::text($row['code'] ?? null)];
            }
        }

        $name = $request->request->has('scaleName') ? self::text($request->request->get('scaleName')) : null;

        try {
            $this->ranks->saveScale($scale, $name, $rows, self::text($request->request->get('newName')), self::text($request->request->get('newCode')));
        } catch (\InvalidArgumentException $refusal) {
            return $this->back($request, $scale, $refusal->getMessage(), 'error');
        }

        return $this->back($request, $scale, 'Saved. Order is seniority.');
    }

    /** A RANK TAKEN OFF THE LIST: retired when somebody held it, deleted when nobody did. */
    #[Route('/team/configure/ranks/rank/{uuid}/remove', name: 'team_configure_ranks_remove', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::CONFIGURE)]
    public function remove(Request $request, string $uuid): RedirectResponse
    {
        $this->assertCsrf($request);
        $rank = $this->rankRepository->findOneBy(['uuid' => $uuid]);
        if (!$rank instanceof Rank) {
            throw new NotFoundHttpException('No such rank.');
        }

        $scale = $rank->getScale();
        $name = $rank->getName();
        $held = $this->holdings->isEverHeld($rank);
        $this->ranks->remove($rank);

        return $this->back($request, $scale, $held ? \sprintf('%s is retired. Its holders keep it and their history reads on.', $name) : \sprintf('%s is removed.', $name));
    }

    /** A RANK MOVED TO ANOTHER SCALE — its holders and history with it, at that scale's junior end. */
    #[Route('/team/configure/ranks/rank/{uuid}/move/{scaleUuid}', name: 'team_configure_ranks_move', requirements: ['uuid' => Requirement::UUID, 'scaleUuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::CONFIGURE)]
    public function move(Request $request, string $uuid, string $scaleUuid): RedirectResponse
    {
        $this->assertCsrf($request);
        $rank = $this->rankRepository->findOneBy(['uuid' => $uuid]);
        $to = $this->scales->findOneBy(['uuid' => $scaleUuid, 'retiredAt' => null]);
        if (!$rank instanceof Rank || !$to instanceof RankScale) {
            throw new NotFoundHttpException('No such rank or scale.');
        }
        $from = $rank->getScale();

        try {
            $this->ranks->moveRank($rank, $to);
        } catch (\InvalidArgumentException $refusal) {
            return $this->back($request, $from, $refusal->getMessage(), 'error');
        }

        return $this->back($request, $to, \sprintf('%s moved to the %s scale, at its junior end.', $rank->getName(), (string) $to->getName()));
    }

    /** A SCALE TAKEN OFF THE LIST once nothing live is on it: retired with its retired ranks, deleted when it never had one. */
    #[Route('/team/configure/ranks/scales/{uuid}/remove', name: 'team_configure_ranks_scale_remove', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::CONFIGURE)]
    public function removeScale(Request $request, string $uuid): RedirectResponse
    {
        $this->assertCsrf($request);
        $scale = $this->scales->findOneBy(['uuid' => $uuid, 'retiredAt' => null]);
        if (!$scale instanceof RankScale) {
            throw new NotFoundHttpException('No such scale.');
        }
        $name = (string) $scale->getName();

        try {
            $this->ranks->removeScale($scale);
        } catch (\InvalidArgumentException $refusal) {
            return $this->back($request, $scale, $refusal->getMessage(), 'error');
        }

        return $this->back($request, null, \sprintf('The %s scale is gone from the list.', $name));
    }

    /** A SCALE FOR RANKS THAT DO NOT COMPARE WITH THE OTHERS. */
    #[Route('/team/configure/ranks/scales', name: 'team_configure_ranks_scale_add', methods: ['POST'])]
    #[IsGranted(self::CONFIGURE)]
    public function addScale(Request $request): RedirectResponse
    {
        $this->assertCsrf($request);

        try {
            $scale = $this->ranks->addScale(self::text($request->request->get('name')), self::text($request->request->get('firstScaleName')));
        } catch (\InvalidArgumentException $refusal) {
            $session = $request->hasSession() ? $request->getSession() : null;
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('error', $refusal->getMessage());
            }

            return new RedirectResponse($this->router->generate(self::SECTION, ['add' => 'scale']).'#add-scale');
        }

        return $this->back($request, $scale, \sprintf('The %s scale is added. Give it its ranks.', $scale->getName()));
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, (string) $request->request->get('_token')))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }

    /** BACK TO THE CARD THE EDIT WAS MADE ON, so the sentence is read beside the thing it is about. */
    private function back(Request $request, ?RankScale $scale, string $message, string $kind = 'success'): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($kind, $message);
        }

        $anchor = null === $scale ? '' : '#scale-'.$scale->getUuidString();

        return new RedirectResponse($this->router->generate(self::SECTION).$anchor);
    }
}
