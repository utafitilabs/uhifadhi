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

namespace Uhifadhi\Bundle\AreaBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PING EVERY AND STALE AFTER LEAVE THE AREA: they are Settings › Core's now,
 * set by Super Admins and Admins for the organization and customised per area
 * in the settings store. A clean start (ruled 1 Oct 2026): the columns go and
 * nothing is copied; every area runs at the defaults until an Admin sets them.
 */
final class Version20261001000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'area_of_interest: ping_interval_minutes and stale_after_minutes go to the settings store';
    }

    /** @destructive core 0.2.0 — drops each area's ping interval and stale-after; a clean start (ruled 1 Oct 2026), every area runs at Settings › Core's values. */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE area_of_interest DROP ping_interval_minutes');
        $this->addSql('ALTER TABLE area_of_interest DROP stale_after_minutes');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE area_of_interest ADD ping_interval_minutes INT DEFAULT NULL');
        $this->addSql('ALTER TABLE area_of_interest ADD stale_after_minutes INT DEFAULT NULL');
    }
}
