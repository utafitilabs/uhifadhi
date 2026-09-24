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

namespace Uhifadhi\Bundle\TeamBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * THE RULES THE WHOLE TEAM IS RUN UNDER — one row, written here with the
 * defaults the product reads before anybody has saved a card, so a configure
 * section opens on a value and never on a blank.
 *
 * AND WHEN AN INVITATION STOPS OPENING. The validity rule is stamped on the
 * link when it is sent, so tightening the rule later leaves links already in
 * an inbox as they were promised. Nullable, with no backfill: a link sent
 * before there was a rule was sent under none, and that null is the honest
 * record of it.
 */
final class Version20260924000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'team_settings, its one row, and team_user.invitation_expires_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE team_settings (
                id INT NOT NULL,
                invitation_valid_amount INT NOT NULL,
                invitation_valid_unit VARCHAR(255) NOT NULL,
                invitation_uses INT NOT NULL,
                invitation_with_password BOOLEAN NOT NULL,
                two_stations_allowed BOOLEAN NOT NULL,
                leaders_per_station INT NOT NULL,
                empty_station_allowed BOOLEAN NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO team_settings (id, invitation_valid_amount, invitation_valid_unit, invitation_uses, invitation_with_password, two_stations_allowed, leaders_per_station, empty_station_allowed)
            VALUES (1, 7, 'days', 1, TRUE, TRUE, 1, TRUE)
            SQL);
        $this->addSql('ALTER TABLE team_user ADD invitation_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_user DROP invitation_expires_at');
        $this->addSql('DROP TABLE team_settings');
    }
}
