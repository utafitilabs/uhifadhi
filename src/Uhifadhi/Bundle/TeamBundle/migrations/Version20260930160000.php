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
 * MY PROFILE (ruled 30 Sep, #69): a phone the person gives, and an email
 * change waiting to be confirmed from the new address. Schema only.
 */
final class Version20260930160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'team_user.phone, pending_email, pending_email_token, pending_email_requested_at, password_set_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_user ADD phone VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE team_user ADD pending_email VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE team_user ADD pending_email_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE team_user ADD pending_email_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE team_user ADD password_set_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_user DROP password_set_at');
        $this->addSql('ALTER TABLE team_user DROP pending_email_requested_at');
        $this->addSql('ALTER TABLE team_user DROP pending_email_token');
        $this->addSql('ALTER TABLE team_user DROP pending_email');
        $this->addSql('ALTER TABLE team_user DROP phone');
    }
}
