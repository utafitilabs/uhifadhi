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
 * WHO OUTSIDE A DEPARTMENT SEES IT (ruled 11 Oct 2026, #74). Every department
 * starts Private, which is who read one before this column existed.
 */
final class Version20261012090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'team_department: visibility, private by default';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE team_department ADD visibility VARCHAR(20) DEFAULT 'private' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_department DROP visibility');
    }
}
