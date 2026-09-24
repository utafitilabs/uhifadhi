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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\TeamBundle\Entity\TeamSettings;
use Uhifadhi\Bundle\TeamBundle\Enum\InvitationUnitEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\TeamSettingsRepository;
use Uhifadhi\Bundle\TeamBundle\Service\TeamSettingsService;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * THE RULES THE WHOLE TEAM IS RUN UNDER — one row, read by every screen that
 * states them and written by the three configure sections.
 *
 * AN INSTALLATION THAT HAS NEVER SAVED THEM STILL HAS THEM. The shipped
 * migration writes the row; a schema built from the entities alone (as this
 * suite's is) has none, and the service answers the defaults rather than a
 * page that cannot render until somebody has pressed Save.
 */
#[CoversClass(TeamSettingsService::class)]
final class TeamSettingsServiceTest extends IntegrationTestCase
{
    public function testAnInstallationThatNeverSavedReadsTheDefaults(): void
    {
        $rules = $this->settings()->current();

        self::assertSame(7, $rules->getInvitationValidAmount());
        self::assertSame(InvitationUnitEnum::Days, $rules->getInvitationValidUnit());
        self::assertSame(1, $rules->getInvitationUses());
        self::assertTrue($rules->isInvitationWithPassword());
        self::assertTrue($rules->isTwoStationsAllowed());
        self::assertSame(1, $rules->getLeadersPerStation());
        self::assertTrue($rules->isEmptyStationAllowed());
        self::assertNull($this->stored()->find(TeamSettings::ONE), 'reading writes nothing');
    }

    public function testTheInvitationRulesAreStoredInTheOneRow(): void
    {
        $this->settings()->setInvitationRules(48, InvitationUnitEnum::Hours, 2, false);

        $row = $this->stored()->find(TeamSettings::ONE);
        self::assertInstanceOf(TeamSettings::class, $row);
        self::assertSame(48, $row->getInvitationValidAmount());
        self::assertSame(InvitationUnitEnum::Hours, $row->getInvitationValidUnit());
        self::assertSame(2, $row->getInvitationUses());
        self::assertFalse($row->isInvitationWithPassword());
        // The other card's rules are untouched by this card's save.
        self::assertTrue($row->isTwoStationsAllowed());
    }

    public function testTheStationingRulesAreStoredInTheSameRow(): void
    {
        $this->settings()->setInvitationRules(3, InvitationUnitEnum::Days, 1, true);
        $this->settings()->setStationingRules(false, 2, false);

        $row = $this->stored()->find(TeamSettings::ONE);
        self::assertInstanceOf(TeamSettings::class, $row);
        self::assertFalse($row->isTwoStationsAllowed());
        self::assertSame(2, $row->getLeadersPerStation());
        self::assertFalse($row->isEmptyStationAllowed());
        self::assertSame(3, $row->getInvitationValidAmount(), 'the first card\'s save survives the second');
        self::assertCount(1, $this->stored()->findAll(), 'there is one row, however often it is saved');
    }

    /** The validity is what the invitation flow stamps on a link. */
    public function testTheValidityReadsAsAnInterval(): void
    {
        $this->settings()->setInvitationRules(36, InvitationUnitEnum::Hours, 1, true);

        $since = new \DateTimeImmutable('2026-09-24 10:00:00');
        self::assertSame('2026-09-25 22:00:00', $this->settings()->current()->invitationExpiry($since)->format('Y-m-d H:i:s'));
    }

    public function testAValidityBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->settings()->setInvitationRules(0, InvitationUnitEnum::Days, 1, true);
    }

    public function testFewerThanOneUseIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->settings()->setInvitationRules(7, InvitationUnitEnum::Days, 0, true);
    }

    public function testFewerThanOneLeaderIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->settings()->setStationingRules(true, 0, true);
    }

    private function settings(): TeamSettingsService
    {
        return $this->service(TeamSettingsService::class);
    }

    private function stored(): TeamSettingsRepository
    {
        $this->em->clear();

        return $this->service(TeamSettingsRepository::class);
    }
}
