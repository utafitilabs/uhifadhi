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

namespace Uhifadhi\Bundle\AreaBundle\Me;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Controller\MeController;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\People\AreaStationPlates;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Contracts\Me\MyCard;
use Uhifadhi\Contracts\Me\MyCardProviderInterface;

/**
 * WHAT THE GROUND SHOWS A PERSON ABOUT THEMSELVES on their own dashboard (#19,
 * option A ruled 28 Sep 2026): my watch (a figure), my post on the plate, my
 * station — who leads it, how many are posted there — my check-ins, and the
 * doors to My station and My duty log.
 *
 * Only this person's own records, and whatever they may read of the area: a
 * ranger is shown the post they are posted at because it is theirs, not
 * because a grant says so.
 */
final readonly class AreaMyCards implements MyCardProviderInterface
{
    /** How many claims the card lists. */
    public const int CHECKINS = 5;

    public function __construct(
        private Environment $twig,
        private PostingRepository $postings,
        private CheckInRepository $checkIns,
        private AreaStationPlates $plates,
        private UrlGeneratorInterface $router,
    ) {
    }

    public function cardsFor(string $personUuid, \DateTimeImmutable $now): array
    {
        $cards = [];
        $claims = $this->checkIns->findRecentForPerson($personUuid, self::CHECKINS);
        $open = null;
        foreach ($claims as $claim) {
            if (null === $claim->getEndedAt()) {
                $open = $claim;
                break;
            }
        }

        $cards[] = new MyCard(MyCard::FIGURE, 10, $this->twig->render('@Area/me/_watch_figure.html.twig', [
            'open' => $open,
            'last' => $claims[0] ?? null,
            'now' => $now,
        ]));

        $posting = $this->postings->findStandingByPersonUuids([$personUuid])[0] ?? null;
        $station = $posting?->getStation();
        if (null !== $station) {
            $cards[] = new MyCard(MyCard::DOOR, 20, \sprintf('<a href="%s"><b>My station</b> &middot; %s</a>', $this->router->generate(MeController::STATION), htmlspecialchars((string) $station->getName())));
            $lead = $this->postings->findLeaderAt($station);
            $cards[] = new MyCard(MyCard::LEFT, 20, $this->twig->render('@Area/me/_station_card.html.twig', [
                'station' => $station,
                'lead' => $lead?->getPerson(),
                'posted' => $this->postings->countStandingByStation($station),
                'since' => $posting->getSince(),
                'door' => $this->router->generate(MeController::STATION),
            ]));
            $plate = $this->plates->plateOf($station);
            if (null !== $plate) {
                $cards[] = new MyCard(MyCard::PLATE, 10, $this->twig->render('@Area/me/_plate_card.html.twig', ['plate' => $plate->html, 'station' => $station]));
            }
        }

        $cards[] = new MyCard(MyCard::DOOR, 30, \sprintf('<a href="%s"><b>My duty log</b> &middot; %s</a>', $this->router->generate(MeController::DUTY_LOG), strtolower($now->format('F'))));
        $cards[] = new MyCard(MyCard::RIGHT, 10, $this->twig->render('@Area/me/_checkins_card.html.twig', [
            'claims' => $claims,
            'now' => $now,
            'door' => $this->router->generate(MeController::DUTY_LOG),
        ]));

        return $cards;
    }

    /** Hours and minutes between two moments, as the design prints them: `8 h 04`. */
    public static function span(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $minutes = max(0, intdiv($to->getTimestamp() - $from->getTimestamp(), 60));

        return \sprintf('%d h %02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** @return list<CheckIn> */
    public function claimsFor(string $personUuid, int $limit, ?\DateTimeImmutable $since = null): array
    {
        return $this->checkIns->findRecentForPerson($personUuid, $limit, $since);
    }
}
