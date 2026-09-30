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
 * WHAT THE SIGN-IN CARD READS (ruled 30 Sep, #67, design D): the last web
 * sign-in, and when a reset link was last sent and by whom. Schema only:
 * nothing recorded them before, so nothing is filled in.
 */
final class Version20260930130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'team_user.last_signed_in_at, reset_link_sent_at, reset_link_sent_by_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_user ADD last_signed_in_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE team_user ADD reset_link_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE team_user ADD reset_link_sent_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE team_user ADD CONSTRAINT FK_5C72223224272C65 FOREIGN KEY (reset_link_sent_by_id) REFERENCES team_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_5C72223224272C65 ON team_user (reset_link_sent_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_user DROP CONSTRAINT FK_5C72223224272C65');
        $this->addSql('DROP INDEX IDX_5C72223224272C65');
        $this->addSql('ALTER TABLE team_user DROP reset_link_sent_by_id');
        $this->addSql('ALTER TABLE team_user DROP reset_link_sent_at');
        $this->addSql('ALTER TABLE team_user DROP last_signed_in_at');
    }
}
