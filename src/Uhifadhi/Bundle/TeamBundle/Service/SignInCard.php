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

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Repository\ApiTokenRepository;
use Uhifadhi\Bundle\TeamBundle\Security\AreaAuthority;
use Uhifadhi\Bundle\TeamBundle\Security\MemberVoter;

/**
 * THE SIGN-IN CARD ON A PERSON'S PAGE (ruled 30 Sep, #67, design D): can they
 * get in, where a link would go, when one last went - asked for whoever holds
 * Sign-in help and may reach the person, and nothing for anybody else.
 *
 * THE ADDRESS IS WHOLE only to a viewer who reads personal details; anybody
 * else reads its first letter and its domain. It is the one address a link
 * can go to, and only the tiers change it.
 */
final readonly class SignInCard
{
    public const string PAIR = 'sign-in-help.manage';

    /** A send this recent is "just now": the page drawn straight after it. */
    private const int JUST_NOW_SECONDS = 60;

    public function __construct(
        private AuthorizationCheckerInterface $authorization,
        private AreaAuthority $authority,
        private ApiTokenRepository $tokens,
    ) {
    }

    /**
     * @return array{active: bool, verified: bool, signedInAt: ?\DateTimeImmutable, signedInOn: ?string, address: string, sentAt: ?\DateTimeImmutable, sentJustNow: bool, sentBy: ?string}|null
     */
    public function for(User $member, ?\DateTimeImmutable $now = null): ?array
    {
        if (!$this->mayHelp($member)) {
            return null;
        }

        $now ??= new \DateTimeImmutable();
        $web = $member->getLastSignedInAt();
        $handset = $this->tokens->lastUsedAtFor($member);
        [$signedInAt, $signedInOn] = match (true) {
            null === $web && null === $handset => [null, null],
            null === $web || (null !== $handset && $handset > $web) => [$handset, 'on the handset'],
            default => [$web, 'on the web'],
        };
        $sentAt = $member->getResetLinkSentAt();

        return [
            'active' => $member->isActive(),
            'verified' => $member->isVerified(),
            'signedInAt' => $signedInAt,
            'signedInOn' => $signedInOn,
            'address' => $this->addressFor($member),
            'sentAt' => $sentAt,
            'sentJustNow' => null !== $sentAt && $now->getTimestamp() - $sentAt->getTimestamp() < self::JUST_NOW_SECONDS,
            'sentBy' => $member->getResetLinkSentBy()?->getFullName(),
        ];
    }

    /** Whether the signed-in viewer may send this person a link: the pair, the tier rule, their ground. */
    public function mayHelp(User $member): bool
    {
        return $this->authorization->isGranted(self::PAIR)
            && $this->authorization->isGranted(MemberVoter::CONFIGURE, $member)
            && ($this->authority->isUnbounded() || $this->authority->reachesPerson($member));
    }

    /** The address as this viewer may read it. */
    public function addressFor(User $member): string
    {
        $email = (string) $member->getEmail();
        if ($this->authorization->isGranted('personal-details.read')) {
            return $email;
        }

        $at = strrpos($email, '@');

        return false === $at ? '•••' : mb_substr($email, 0, 1).'•••'.substr($email, $at);
    }
}
