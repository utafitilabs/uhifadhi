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

namespace Uhifadhi\Bundle\AreaBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\People\AreaStationPlates;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Service\StationSectionService;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Contracts\Area\StationSurface;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Contracts\Kpi\StationRef;

/**
 * THE PAGES OF A PERSON'S OWN (#19, ruled 28 Sep 2026): My station and My duty
 * log, behind the doors on their dashboard. They name no permission, because
 * they show nothing but the signed-in person's own records — the post they are
 * posted at, and the check-ins they made — and the route test lists them as
 * deliberately open to anybody signed in, with that reason.
 */
final readonly class MeController
{
    public const string STATION = 'me_station';
    public const string DUTY_LOG = 'me_duty_log';

    /** A month of claims is at most one a day, twice. */
    private const int MONTH = 70;

    public function __construct(
        private Environment $twig,
        private TokenStorageInterface $tokens,
        private PostingRepository $postings,
        private CheckInRepository $checkIns,
        private AreaStationPlates $plates,
        private StationSectionService $sections,
        private AreaModuleService $areaModules,
    ) {
    }

    #[Route('/me/station', name: self::STATION, methods: ['GET'])]
    public function station(): Response
    {
        $me = $this->me();
        $posting = $this->postings->findStandingByPersonUuids([(string) $me->getUuidString()])[0] ?? null;
        $station = $posting?->getStation();
        $area = $station?->getArea();
        if (null === $station || null === $area) {
            return new Response($this->twig->render('@Area/me/station.html.twig', ['station' => null]));
        }

        $onWatch = [];
        foreach ($this->checkIns->findOpenIn($area) as $open) {
            if ($open->getStation()?->getId() === $station->getId() && null !== $open->getPerson()) {
                $onWatch[(string) $open->getPerson()->getUuidString()] = $open;
            }
        }

        return new Response($this->twig->render('@Area/me/station.html.twig', [
            'station' => $station,
            'area' => $area,
            'lead' => $this->postings->findLeaderAt($station),
            'posted' => $this->postings->findStandingByStation($station),
            'onWatch' => $onWatch,
            'me' => $me,
            'plate' => $this->plates->plateOf($station)?->html,
            'bands' => $this->sections->forOne(
                new StationRef((string) $station->getUuidString(), (string) $area->getUuidString(), (string) $station->getName()),
                StationSurface::Mine,
                fn (string $slug): bool => $this->areaModules->isActive($area, $slug),
            ),
        ]));
    }

    #[Route('/me/duty-log', name: self::DUTY_LOG, methods: ['GET'])]
    public function dutyLog(Request $request): Response
    {
        $me = $this->me();
        $month = \DateTimeImmutable::createFromFormat('!Y-m', $request->query->getString('month')) ?: new \DateTimeImmutable('first day of this month midnight');
        $month = $month->modify('first day of this month')->setTime(0, 0);
        $next = $month->modify('+1 month');
        $now = new \DateTimeImmutable();

        $claims = array_values(array_filter(
            $this->checkIns->findRecentForPerson((string) $me->getUuidString(), self::MONTH, $month),
            static fn (CheckIn $c): bool => null !== $c->getOccurredAt() && $c->getOccurredAt() < $next,
        ));

        $minutes = 0;
        $days = [];
        $pings = 0;
        foreach ($claims as $claim) {
            $from = $claim->getOccurredAt();
            if (null === $from) {
                continue;
            }
            $minutes += max(0, intdiv(($claim->getEndedAt() ?? $now)->getTimestamp() - $from->getTimestamp(), 60));
            $days[$from->format('Y-m-d')] = true;
            $pings += $claim->getPingCount();
        }

        return new Response($this->twig->render('@Area/me/duty_log.html.twig', [
            'claims' => $claims,
            'month' => $month,
            'previous' => $month->modify('-1 month'),
            'next' => $next <= $now ? $next : null,
            'now' => $now,
            'hours' => intdiv($minutes, 60),
            'days' => \count($days),
            'watches' => \count($claims),
            'pings' => $pings,
        ]));
    }

    private function me(): UserInterface
    {
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof UserInterface || null === $user->getUuidString()) {
            throw new AccessDeniedException('These pages are a person\'s own, and need an account of your own.');
        }

        return $user;
    }
}
