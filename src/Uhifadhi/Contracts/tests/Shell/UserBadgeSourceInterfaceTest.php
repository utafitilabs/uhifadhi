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

namespace Uhifadhi\Contracts\Tests\Shell;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Shell\UserBadge;
use Uhifadhi\Contracts\Shell\UserBadgeSourceInterface;

/**
 * WHO THE TOP BAR NAMES, as a contract. One verb — badge() — and the
 * load-bearing thing to prove is what it hands back: a {@see UserBadge} value
 * object, plain strings, and NOT a UserInterface. That is what lets the shell
 * draw a viewer's card without requiring the package that defines an account,
 * and lets anything that knows who is signed in — a host, or a team-aware core
 * bundle — implement the contract depending only on this package.
 */
final class UserBadgeSourceInterfaceTest extends TestCase
{
    public function testTheContractPublishesASingleBadgeVerbReturningTheValueObject(): void
    {
        $reflection = new \ReflectionClass(UserBadgeSourceInterface::class);

        self::assertTrue($reflection->isInterface(), 'The user-badge contract is an interface, not a class.');

        $declared = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(),
        );

        self::assertSame(['badge'], $declared);

        $badge = new \ReflectionMethod(UserBadgeSourceInterface::class, 'badge');
        self::assertSame([], $badge->getParameters(), 'The shell passes nothing: the source resolves the request itself.');
        self::assertSame('?'.UserBadge::class, (string) $badge->getReturnType(), 'The source hands back a composed card, or null when there is no viewer.');
    }

    /**
     * THE CONTRACT HANDS OVER STRINGS, NEVER AN ACCOUNT. The contract imports
     * nothing — its own value object is same-namespace, and there is no
     * UserInterface and no framework in sight — so a module can implement it
     * without the shell ever seeing the account model.
     */
    public function testTheContractTrafficsInTheValueObjectAndNamesNoAccount(): void
    {
        $file = (new \ReflectionClass(UserBadgeSourceInterface::class))->getFileName();
        self::assertIsString($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        self::assertSame(0, preg_match('/^use /m', $source), 'The user-badge contract imports nothing: UserBadge is same-namespace, and no account model is imported.');
        self::assertSame(0, preg_match('/^use .*UserInterface/m', $source), 'The card is data, not an account narrowed: the contract imports no UserInterface.');
    }

    public function testASourceComposesACardOrNamesNobody(): void
    {
        $named = new class implements UserBadgeSourceInterface {
            public function badge(): UserBadge
            {
                return UserBadge::fromName('N. Kileo', 'UCA · operator');
            }
        };

        $anonymous = new class implements UserBadgeSourceInterface {
            public function badge(): ?UserBadge
            {
                return null;
            }
        };

        self::assertInstanceOf(UserBadge::class, $named->badge());
        self::assertNull($anonymous->badge(), 'A source that knows no viewer names nobody.');
    }
}
