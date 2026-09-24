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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Shell\UserBadge;

/**
 * THE VIEWER'S CARD, AS DATA — a name, its initials, and an optional context
 * line ("UCA · operator"). The value object carries plain strings and nothing
 * else: it names no account class and imports nothing, because the shell that
 * draws it requires no module and no user package. Whoever knows who is signed
 * in composes one of these and hands it over through the contract.
 */
final class UserBadgeTest extends TestCase
{
    public function testItCarriesTheThreeThingsTheCardDraws(): void
    {
        $badge = new UserBadge('N. Kileo', 'NK', 'UCA · operator');

        self::assertSame('N. Kileo', $badge->name);
        self::assertSame('NK', $badge->initials);
        self::assertSame('UCA · operator', $badge->context);
    }

    public function testTheContextLineIsOptional(): void
    {
        $badge = new UserBadge('N. Kileo', 'NK');

        self::assertNull($badge->context, 'A badge with no org/role is a name and an avatar, not an empty line.');
    }

    /**
     * THE FALLBACK, AS A FACTORY. A source that has only a name — the least it
     * can know, e.g. straight off a UserInterface::getFullName() — gets a badge
     * with derived initials and no context line. This is where the one bit of
     * composition the shell owns lives: turning a printed name into two letters.
     *
     * @param non-empty-string $name
     */
    #[DataProvider('names')]
    public function testFromNameDerivesInitialsFromThePrintedName(string $name, string $expected): void
    {
        self::assertSame($expected, UserBadge::fromName($name)->initials);
    }

    /**
     * @return \Generator<string, array{string, string}>
     */
    public static function names(): \Generator
    {
        yield 'first-initial and surname' => ['N. Kileo', 'NK'];
        yield 'two full names' => ['Naserian Kileo', 'NK'];
        yield 'three names take first and last' => ['Naserian Ole Kileo', 'NK'];
        yield 'a single name gives one letter' => ['Kamal', 'K'];
        yield 'lowercase is upcased' => ['naserian kileo', 'NK'];
        yield 'stray whitespace is ignored' => ['  Naserian   Kileo  ', 'NK'];
        yield 'a leading non-letter is skipped' => ['(N) Kileo', 'NK'];
        yield 'accented letters survive' => ['Émile Zola', 'ÉZ'];
    }

    public function testFromNameKeepsTheNameAndDefaultsTheContextToNull(): void
    {
        $badge = UserBadge::fromName('N. Kileo');

        self::assertSame('N. Kileo', $badge->name);
        self::assertNull($badge->context);
    }

    public function testFromNameCarriesAContextLineWhenGivenOne(): void
    {
        $badge = UserBadge::fromName('N. Kileo', 'UCA · operator');

        self::assertSame('UCA · operator', $badge->context);
        self::assertSame('NK', $badge->initials);
    }
}
