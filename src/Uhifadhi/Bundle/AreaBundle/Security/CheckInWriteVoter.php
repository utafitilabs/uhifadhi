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

namespace Uhifadhi\Bundle\AreaBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Contracts\Entity\UserInterface;

/**
 * WHO MAY CHANGE A CHECK-IN ALREADY CLAIMED (ruled 30 Sep, #67, class 6).
 *
 * Its owner; the head of a station the owner is posted at, who checks the
 * phoneless crew member in and out; and the tiers above the matrix. Anybody
 * else who records duty in the same area - a colleague, the head of another
 * station - is refused: a check-in is evidence of who was on duty where.
 *
 * THE TIERS ARE READ FROM THE TOKEN'S ROLES, as every module reads them.
 *
 * @see https://symfony.com/doc/current/security/voters.html
 *
 * @extends Voter<string, CheckIn>
 */
final class CheckInWriteVoter extends Voter
{
    public const string WRITE = 'checkin.write';

    private const array TIERS = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    public function __construct(private readonly PostingRepository $postings)
    {
    }

    /** @see https://symfony.com/doc/current/security/voters.html#improving-voter-performance */
    public function supportsAttribute(string $attribute): bool
    {
        return self::WRITE === $attribute;
    }

    /** A Doctrine proxy extends the entity, so the type is matched with is_a(), as the documentation's example does. */
    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, CheckIn::class, true);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::WRITE === $attribute && $subject instanceof CheckIn;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ([] !== array_intersect(self::TIERS, $token->getRoleNames())) {
            return true;
        }

        $caller = $token->getUser();
        $owner = $subject->getPerson();
        if (!$caller instanceof UserInterface || !$owner instanceof UserInterface || null === $caller->getId()) {
            return false;
        }

        if ($owner->getId() === $caller->getId()) {
            return true;
        }

        foreach ($this->postings->findStandingByPerson($owner) as $posting) {
            $station = $posting->getStation();
            if (null !== $station && ($this->postings->findStandingFor($station, $caller)?->isLeader() ?? false)) {
                return true;
            }
        }

        $vote?->addReason('only its owner, or the head of a station they are posted at, changes a check-in.');

        return false;
    }
}
