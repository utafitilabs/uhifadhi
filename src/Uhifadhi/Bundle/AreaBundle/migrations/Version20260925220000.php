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
 * WHERE A STATION'S POINT CAME FROM — surveyed or estimated.
 *
 * EXPAND, BACKFILL, CONTRACT, IN ONE MIGRATION. The column is added nullable,
 * every station already stored is marked surveyed (each of those points was
 * placed by somebody, on the form or on the configure page), and only then is
 * the column made NOT NULL. Nothing existing is altered and nothing is dropped.
 */
final class Version20260925220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'station.position_source: surveyed or estimated, existing stations backfilled as surveyed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station ADD position_source VARCHAR(16) DEFAULT NULL');
        $this->addSql("UPDATE station SET position_source = 'surveyed'");
        $this->addSql('ALTER TABLE station ALTER position_source SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station DROP position_source');
    }
}
