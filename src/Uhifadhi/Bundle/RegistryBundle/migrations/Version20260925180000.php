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

namespace Uhifadhi\Bundle\RegistryBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * THE FACTS LEDGER: one row per subject, figure and period, which the next
 * run of that period replaces (`INSERT … ON CONFLICT DO UPDATE` on the
 * primary key). The second index serves the schedule's question "when was
 * this figure last computed for this period", which asks across subjects.
 *
 * A new table, so nothing is backfilled: the first `uhifadhi:facts:rebuild`
 * fills it.
 */
final class Version20260925180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'figure_fact';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE figure_fact (subject_kind VARCHAR(64) NOT NULL, subject_uuid UUID NOT NULL, figure_key VARCHAR(128) NOT NULL, period_key VARCHAR(7) NOT NULL, value DOUBLE PRECISION DEFAULT NULL, computed_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (subject_kind, subject_uuid, figure_key, period_key))');
        $this->addSql('CREATE INDEX idx_figure_fact_figure_period ON figure_fact (figure_key, period_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE figure_fact');
    }
}
