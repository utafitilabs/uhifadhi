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

namespace Uhifadhi\Bundle\TeamBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\Rank;
use Uhifadhi\Bundle\TeamBundle\Entity\RankHolding;
use Uhifadhi\Bundle\TeamBundle\Entity\RankScale;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Repository\RankHoldingRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\RankRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\RankScaleRepository;

/**
 * THE ORGANIZATION'S RANKS, WRITTEN — the scales, the ranks in seniority
 * order on each, and the rank each person holds.
 *
 * ONE SCALE BY DEFAULT. The shipped migration writes it; a schema built from
 * the entities alone has none, and the first write here makes it, so a
 * configure page never opens on "create a scale first".
 *
 * EVERY REFUSAL IS AN \InvalidArgumentException carrying the sentence the
 * page shows, the convention the team settings follow.
 */
final readonly class RankService
{
    public function __construct(
        private RankScaleRepository $scales,
        private RankRepository $ranks,
        private RankHoldingRepository $holdings,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return list<RankScale> */
    public function scales(): array
    {
        return $this->scales->findAllOrdered();
    }

    /** The organization's first scale, made when none exists yet. */
    public function defaultScale(): RankScale
    {
        $first = $this->scales->findAllOrdered()[0] ?? null;
        if (null !== $first) {
            return $first;
        }

        $scale = new RankScale();
        $this->entityManager->persist($scale);
        $this->entityManager->flush();

        return $scale;
    }

    /** A rank added at the senior end of its scale. */
    public function addRank(RankScale $scale, string $name, string $code): Rank
    {
        [$name, $code] = $this->validRank($scale, $name, $code, null);

        $rank = (new Rank($scale))
            ->setName($name)
            ->setShortCode($code)
            ->setSeniority($this->ranks->getMaxSeniority($scale) + 1);
        $this->entityManager->persist($rank);
        $this->entityManager->flush();

        return $rank;
    }

    /**
     * ONE SCALE'S CARD, SAVED AS ONE WRITE: its name, every rank's name and
     * code, the order the rows were posted in as the seniority, and the add
     * row when it was filled.
     *
     * @param array<string, array{name: string, code: string}> $rows keyed by the rank's uuid, in seniority order
     */
    public function saveScale(RankScale $scale, ?string $name, array $rows, ?string $newName, ?string $newCode): void
    {
        if (null !== $name) {
            $name = trim($name);
            if ('' === $name && \count($this->scales->findAllOrdered()) > 1) {
                throw new \InvalidArgumentException('Every scale is named once there is more than one.');
            }
            $scale->setName('' === $name ? null : mb_substr($name, 0, 80));
        }

        $byUuid = [];
        foreach ($this->ranks->findActiveByScale($scale) as $rank) {
            $byUuid[(string) $rank->getUuidString()] = $rank;
        }

        // TWO PASSES, so two ranks may swap codes in one save: every code is
        // checked against the codes this save leaves, not the ones it found.
        $seen = [];
        foreach ($rows as $uuid => $row) {
            if (!isset($byUuid[$uuid])) {
                continue;
            }
            [$rankName, $code] = $this->cleaned($row['name'], $row['code']);
            $key = mb_strtolower($code);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException(\sprintf('The short code "%s" is used twice on this scale.', $code));
            }
            $seen[$key] = true;
            $byUuid[$uuid]->setName($rankName)->setShortCode($code);
        }

        $seniority = 0;
        foreach ($rows as $uuid => $row) {
            if (isset($byUuid[$uuid])) {
                $byUuid[$uuid]->setSeniority(++$seniority);
            }
        }

        $this->entityManager->flush();

        if (null !== $newName && '' !== trim($newName)) {
            $this->addRank($scale, $newName, (string) $newCode);
        }
    }

    /**
     * A SCALE FOR RANKS THAT DO NOT COMPARE WITH THE OTHERS. When it is the
     * second, the first is named at the same moment, so no page ever reads an
     * unnamed scale beside a named one.
     */
    public function addScale(string $name, ?string $firstScaleName): RankScale
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \InvalidArgumentException('A scale is named for what it is.');
        }

        $existing = $this->scales->findAllOrdered();
        if ([] === $existing) {
            $existing = [$this->defaultScale()];
        }
        foreach ($existing as $scale) {
            if (null !== $scale->getName() && 0 === strcasecmp($scale->getName(), $name)) {
                throw new \InvalidArgumentException(\sprintf('There is already a scale called "%s".', $name));
            }
        }

        $first = $existing[0];
        if (null === $first->getName()) {
            $firstScaleName = trim((string) $firstScaleName);
            if ('' === $firstScaleName) {
                throw new \InvalidArgumentException('Name the scale the organization has, so the two read apart.');
            }
            if (0 === strcasecmp($firstScaleName, $name)) {
                throw new \InvalidArgumentException('The two scales need different names.');
            }
            $first->setName(mb_substr($firstScaleName, 0, 80));
        }

        $scale = (new RankScale())
            ->setName(mb_substr($name, 0, 80))
            ->setSortOrder(max(array_map(static fn (RankScale $s): int => $s->getSortOrder(), $existing)) + 1);
        $this->entityManager->persist($scale);
        $this->entityManager->flush();

        return $scale;
    }

    /** A rank somebody held is retired, never deleted; one nobody held goes. */
    public function remove(Rank $rank): void
    {
        if ($this->holdings->isEverHeld($rank)) {
            $rank->setRetiredAt(new \DateTimeImmutable());
        } else {
            $this->entityManager->remove($rank);
        }

        $this->entityManager->flush();
    }

    /**
     * THE RANK A PERSON HOLDS FROM A DAY ON — or none. The rank held until
     * then closes on that day; the same rank again changes nothing.
     */
    public function assign(User $person, ?Rank $rank, \DateTimeImmutable $since, ?User $recordedBy): void
    {
        $since = $since->setTime(0, 0);
        $current = $this->holdings->findCurrentByPerson($person);

        if (null !== $current && null !== $rank && $current->getRank() === $rank) {
            return;
        }
        if (null !== $rank && $rank->isRetired()) {
            throw new \InvalidArgumentException(\sprintf('%s is retired and is given to nobody.', $rank->getName()));
        }
        if (null !== $current && $since < $current->getSince()) {
            throw new \InvalidArgumentException(\sprintf('The new rank starts before %s, the day the one held now started.', $current->getSince()->format('j M Y')));
        }

        $current?->setUntil($since);

        if (null !== $rank) {
            $holding = (new RankHolding($person, $rank, $since))->setRecordedBy($recordedBy);
            $this->entityManager->persist($holding);
        }

        $this->entityManager->flush();
    }

    /** @return array{string, string} */
    private function validRank(RankScale $scale, string $name, string $code, ?Rank $except): array
    {
        [$name, $code] = $this->cleaned($name, $code);

        foreach ($this->ranks->findActiveByScale($scale) as $rank) {
            if ($rank !== $except && 0 === strcasecmp($rank->getShortCode(), $code)) {
                throw new \InvalidArgumentException(\sprintf('The short code "%s" is already a rank on this scale.', $code));
            }
        }

        return [$name, $code];
    }

    /** @return array{string, string} */
    private function cleaned(string $name, string $code): array
    {
        $name = trim($name);
        $code = trim($code);
        if ('' === $name || '' === $code) {
            throw new \InvalidArgumentException('A rank has a name and a short code.');
        }

        return [mb_substr($name, 0, 120), mb_substr($code, 0, 24)];
    }
}
