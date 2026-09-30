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
 * THE PAIRS ONLY THE TIERS HOLD LEAVE EVERY POSITION (ruled 30 Sep, #67, on
 * the owner's word: the cleanest start, even though it cannot be undone).
 *
 * Who holds which seat, a person's address and sign-in, and the ranks are
 * held by Admins and Super Admins alone. The voter already refuses them on a
 * position; this removes them from what positions store, so no screen or
 * export shows a seat as holding something it cannot use. It cannot be
 * undone: after it, nothing records which positions held them.
 */
final class Version20260930120000 extends AbstractMigration
{
    private const string TIER_ONLY = "ARRAY['directory.manage', 'personal-details.manage', 'positions.configure', 'ranks.configure']";

    public function getDescription(): string
    {
        return 'team_position.grants: remove the pairs only the tiers hold';
    }

    public function up(Schema $schema): void
    {
        // jsonb_exists_any rather than ?|, which DBAL would read as a placeholder.
        $this->addSql(\sprintf(
            'UPDATE team_position SET grants = (grants::jsonb - %1$s::text[])::json WHERE jsonb_exists_any(grants::jsonb, %1$s::text[])',
            self::TIER_ONLY,
        ));
    }

    /**
     * NOTHING TO UNDO IN THE SCHEMA, AND NOTHING THAT CAN BE PUT BACK: which
     * positions held the pairs is not kept anywhere, and a position may not
     * hold them now. The history still unwinds through here.
     */
    public function down(Schema $schema): void
    {
    }
}
