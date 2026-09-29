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
 * An area's "stale after": when its live marks stop being believed. Nullable,
 * because unset reads as two ping intervals.
 */
final class Version20260930010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'area_of_interest.stale_after_minutes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE area_of_interest ADD stale_after_minutes INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE area_of_interest DROP stale_after_minutes');
    }
}
