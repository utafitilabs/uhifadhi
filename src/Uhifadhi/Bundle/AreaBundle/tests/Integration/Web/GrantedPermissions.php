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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * THE VOTER THE TEAM BUNDLE WOULD BE.
 *
 * These screens are gated on CONCERN AND VERB — `areas.read`,
 * `zones.configure`, `assignments.manage`, `modules.read` and the rest — pairs
 * declared by {@see \Uhifadhi\Bundle\AreaBundle\Access\AreaConcerns} and by the
 * registry, answered in a real installation by TeamBundle's grant voter. Team is
 * NOT a dependency of this bundle and must not become one: what this bundle owes
 * is that its screens ASK the question, and what somebody else answers is
 * somebody else's suite.
 *
 * So the suite ships the smallest thing that answers: a voter holding a list of
 * granted pairs. A test that wants to prove a screen closes gives it fewer.
 */
final readonly class GrantedPermissions implements VoterInterface
{
    /** @param list<string> $granted */
    public function __construct(private array $granted)
    {
    }

    public function vote(TokenInterface $token, mixed $subject, array $attributes, ?Vote $vote = null): int
    {
        $decided = false;
        foreach ($attributes as $attribute) {
            if (!\is_string($attribute) || !$this->ours($attribute)) {
                continue;
            }
            $decided = true;
            if (!\in_array($attribute, $this->granted, true)) {
                return self::ACCESS_DENIED;
            }
        }

        return $decided ? self::ACCESS_GRANTED : self::ACCESS_ABSTAIN;
    }

    /**
     * THE CONCERNS THIS SUITE ANSWERS FOR: the five this bundle declares, plus
     * the modules composed onto an area, which the registry declares and these
     * screens ask about. Anything else — a role, another module's concern — is
     * somebody else's question and gets an abstention, never a refusal.
     */
    private const array OURS = ['areas.', 'zones.', 'stations.', 'assignments.', 'duty.', 'locations.', 'modules.'];

    private function ours(string $attribute): bool
    {
        foreach (self::OURS as $concern) {
            if (str_starts_with($attribute, $concern)) {
                return true;
            }
        }

        return false;
    }
}
