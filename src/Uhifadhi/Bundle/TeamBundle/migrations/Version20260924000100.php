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
 * THE FLAT PERMISSION COLUMN GOES. What a position grants is a set of
 * (concern, verb) pairs, written in `grants`; the matrix writes only those,
 * every gate asks only those, and nothing reads this column any more.
 *
 * @destructive drops team_position.permissions — the flat strings the
 *              declared-concern model replaced.
 */
final class Version20260924000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A position grants (concern, verb) pairs, so the flat permission column goes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_position DROP permissions');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE team_position ADD permissions JSON DEFAULT '[]' NOT NULL");
    }
}
