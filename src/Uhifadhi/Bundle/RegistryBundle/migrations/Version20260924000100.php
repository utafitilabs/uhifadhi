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
 * WHAT A MODULE IS, IN ONE LINE. The area's modules register prints it under
 * the module's name; a module that says nothing beyond its name leaves it
 * null, so the column is nullable and no row is backfilled with a sentence
 * nobody wrote.
 */
final class Version20260924000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module.description';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE module ADD description VARCHAR(160) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE module DROP description');
    }
}
