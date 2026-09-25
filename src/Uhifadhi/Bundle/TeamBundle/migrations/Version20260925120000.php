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
 * A SCALE CAN BE RETIRED: the stamp a scale carries once it was taken off the
 * list with its retired ranks, so the holdings behind those ranks keep their
 * history while nothing reads the scale any more. Nullable, no backfill: every
 * scale there is stays read.
 */
final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'team_rank_scale.retired_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_rank_scale ADD retired_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_rank_scale DROP retired_at');
    }
}
