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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\ShellBundle\Model\OrgLockup;
use Uhifadhi\Contracts\Settings\OrganizationIdentity;

/**
 * WHAT THE BAR DRAWS OF THE ORGANIZATION, and where the short name comes from
 * when nobody has set one.
 */
final class OrgLockupTest extends TestCase
{
    public function testTheSetShortNameIsTheOneDrawn(): void
    {
        $lockup = OrgLockup::of(new OrganizationIdentity('Uhifadhi Conservation Authority', 'UCA'));

        self::assertSame('Uhifadhi Conservation Authority', $lockup->name);
        self::assertSame('UCA', $lockup->shortName);
    }

    /**
     * A SHORT NAME IS NOT THE INITIALS, and that is the whole reason the field
     * is editable: a national parks authority is TANAPA, never TNP.
     */
    public function testAShortNameNobodyCouldHaveDerivedIsKeptExactly(): void
    {
        self::assertSame('TANAPA', OrgLockup::of(new OrganizationIdentity('Tanzania National Parks', 'TANAPA'))->shortName);
    }

    public function testAnUnsetShortNameFallsBackToTheNamesInitials(): void
    {
        self::assertSame('UCA', OrgLockup::of(new OrganizationIdentity('Uhifadhi Conservation Authority'))->shortName);
    }

    /** A field somebody emptied is a field nobody set. */
    public function testAShortNameOfNothingButSpaceIsNotASetShortName(): void
    {
        self::assertSame('UCA', OrgLockup::of(new OrganizationIdentity('Uhifadhi Conservation Authority', '  '))->shortName);
    }

    /**
     * A WORD THE WRITER LEFT LOWER CASE IS NOT AN INITIAL — nobody says the
     * "and" in a name out loud when they say the name short.
     */
    public function testTheConjunctionsAreNotInitials(): void
    {
        self::assertSame('KCOHCA', OrgLockup::initials('Kilimani Crater and Olkeju Highlands Conservation Authority'));
    }

    public function testPunctuationDoesNotBecomeAnInitial(): void
    {
        self::assertSame('SWMA', OrgLockup::initials('Sekenke Wildlife-Management Area'));
    }

    /** A name in lower case throughout still has a first letter. */
    public function testANameWithNoCapitalAtAllFallsBackToItsFirstLetter(): void
    {
        self::assertSame('K', OrgLockup::initials('kilimani conservancy'));
    }

    /** The field's own length bounds what is derived for it. */
    public function testTheDerivedInitialsAreBoundedByTheFieldsLength(): void
    {
        $name = 'Alpha Bravo Charlie Delta Echo Foxtrot Golf Hotel India Juliett Kilo Lima Mike';

        self::assertSame(OrgLockup::SHORT_MAX, mb_strlen(OrgLockup::initials($name)));
        self::assertSame('ABCDEFGHIJKL', OrgLockup::initials($name));
    }
}
