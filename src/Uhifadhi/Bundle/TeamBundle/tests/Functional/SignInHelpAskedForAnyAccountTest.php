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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Functional;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\UserAuthorizationCheckerInterface;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Security\SignInHelpVoter;

/**
 * SIGN-IN HELP, ASKED FOR SOMEBODY WHO IS NOT SIGNED IN. The Permissions page
 * asks every rule as each account in turn, while a Super Admin is the one
 * signed in, so the answer must follow the account asked about: a head of
 * station helps the people placed in their own area and nobody beyond it,
 * whoever happens to be reading.
 */
final class SignInHelpAskedForAnyAccountTest extends WebTestCaseWithSchema
{
    public function testTheAnswerFollowsTheAccountAskedAboutAndNotTheOneSignedIn(): void
    {
        $kilimani = $this->area('Kilimani');
        $tambarare = $this->area('Tambarare');
        $hamisi = $this->person('Hamisi', 'Juma');
        $hamisi->setPosition($this->position('Head of station', ['directory.read', 'sign-in-help.manage']));
        $this->place($hamisi, [$kilimani]);
        $grace = $this->person('Grace', 'Ndosi');
        $this->place($grace, [$kilimani]);
        $juma = $this->person('Juma', 'Mollel');
        $this->place($juma, [$tambarare]);
        $chief = $this->person('Asha', 'Kweka', TeamRoleEnum::SuperAdmin);
        $this->em->flush();

        /** @var TokenStorageInterface $tokens */
        $tokens = static::getContainer()->get('security.token_storage');
        $tokens->setToken(new UsernamePasswordToken($chief, 'main', $chief->getRoles()));
        /** @var AuthorizationChecker&UserAuthorizationCheckerInterface $checker */
        $checker = static::getContainer()->get('security.authorization_checker');

        self::assertTrue($checker->isGrantedForUser($hamisi, SignInHelpVoter::HELP, $grace), 'somebody in his own area');
        self::assertFalse($checker->isGrantedForUser($hamisi, SignInHelpVoter::HELP, $juma), 'somebody beyond it, although the Super Admin reading reaches them');
        self::assertTrue($checker->isGranted(SignInHelpVoter::HELP, $juma), 'the Super Admin signed in reaches them');
    }
}
