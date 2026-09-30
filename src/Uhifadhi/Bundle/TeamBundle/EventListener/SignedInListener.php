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

namespace Uhifadhi\Bundle\TeamBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Security\ApiTokenAuthenticator;

/**
 * WHEN SOMEBODY LAST SIGNED IN ON THE WEB, written down for the Sign-in card
 * (ruled 30 Sep, #67, design D).
 *
 * THE HANDSET IS LEFT OUT ON PURPOSE. Its firewall authenticates a bearer
 * token on every request, so every sync would count as a sign-in here; its
 * sign-ins are read from the tokens' last use instead.
 *
 * @see https://symfony.com/doc/current/security.html#security-events
 */
final readonly class SignedInListener
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User || $event->getAuthenticator() instanceof ApiTokenAuthenticator) {
            return;
        }

        $user->signedInAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }
}
