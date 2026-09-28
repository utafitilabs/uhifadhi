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

namespace Uhifadhi\Bundle\TeamBundle\Me;

use Twig\Environment;
use Uhifadhi\Bundle\TeamBundle\Access\Door;
use Uhifadhi\Bundle\TeamBundle\Repository\ApiTokenRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Service\PersonRankService;
use Uhifadhi\Contracts\Me\MyCard;
use Uhifadhi\Contracts\Me\MyCardProviderInterface;
use Uhifadhi\Contracts\People\PersonPostingProviderInterface;

/**
 * WHAT THE TEAM SHOWS A PERSON ABOUT THEMSELVES on their own dashboard (#19,
 * option A ruled 28 Sep 2026): my record — position, rank, department, where
 * I am posted — and my phone, the one I am signed in on.
 *
 * The door to the record itself is drawn only for somebody who may open it,
 * so a ranger is never handed a link that answers 403.
 */
final readonly class TeamMyCards implements MyCardProviderInterface
{
    /** @param iterable<PersonPostingProviderInterface> $postingProviders */
    public function __construct(
        private Environment $twig,
        private UserRepository $users,
        private PersonRankService $ranks,
        private ApiTokenRepository $tokens,
        private Door $door,
        private iterable $postingProviders = [],
    ) {
    }

    public function cardsFor(string $personUuid, \DateTimeImmutable $now): array
    {
        $person = $this->users->findByUuids([$personUuid])[0] ?? null;
        if (null === $person) {
            return [];
        }

        $held = null;
        if ($this->ranks->usesRanks()) {
            foreach ($this->ranks->historyOf($person) as $holding) {
                if (null === $holding->getUntil()) {
                    $held = $holding;
                    break;
                }
            }
        }
        $posting = null;
        foreach ($this->postingProviders as $provider) {
            $posting ??= $provider->postingsFor([$personUuid])[$personUuid][0] ?? null;
        }

        return [
            new MyCard(MyCard::RIGHT, 20, $this->twig->render('@Team/me/_record_card.html.twig', [
                'person' => $person,
                'rank' => $held,
                'posting' => $posting,
                'mayOpen' => $this->door->opens('directory.read'),
            ])),
            new MyCard(MyCard::ROW, 30, $this->twig->render('@Team/me/_phone_card.html.twig', [
                'token' => $this->tokens->findLatestLiveFor($person, $now),
            ])),
        ];
    }
}
