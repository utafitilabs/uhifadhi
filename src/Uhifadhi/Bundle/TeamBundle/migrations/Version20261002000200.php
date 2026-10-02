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
 * ONE DEPARTMENT EACH, ANY NUMBER SUPPORTED (ruled 2 Oct 2026). A placement
 * stops naming a set of departments, or all of them: it names the one the
 * person belongs to and, separately, the ones they support. A clean start
 * (ruled 1 Oct 2026): the old set and the "all departments" flag go and
 * nothing is copied; every person is filed in a department again.
 */
final class Version20261002000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'team_placement: one department_id and a team_placement_support list replace team_placement_department and all_departments';
    }

    /** @destructive core 0.2.0 — drops every placement's departments and the "all departments" flag; a clean start (ruled 1 Oct 2026), each person is filed in one department again. */
    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE team_placement_department');
        $this->addSql('ALTER TABLE team_placement DROP all_departments');

        $this->addSql('ALTER TABLE team_placement ADD department_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE team_placement ADD CONSTRAINT fk_team_placement_department FOREIGN KEY (department_id) REFERENCES team_department (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_FFDE6BE9AE80F5DF ON team_placement (department_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE team_placement_support (
                placement_id INT NOT NULL,
                department_id INT NOT NULL,
                PRIMARY KEY(placement_id, department_id)
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_332965E2F966E9D ON team_placement_support (placement_id)');
        $this->addSql('CREATE INDEX IDX_332965EAE80F5DF ON team_placement_support (department_id)');
        $this->addSql('ALTER TABLE team_placement_support ADD CONSTRAINT fk_team_placement_support_placement FOREIGN KEY (placement_id) REFERENCES team_placement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE team_placement_support ADD CONSTRAINT fk_team_placement_support_department FOREIGN KEY (department_id) REFERENCES team_department (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE team_placement_support');
        $this->addSql('ALTER TABLE team_placement DROP department_id');
        $this->addSql('ALTER TABLE team_placement ADD all_departments BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql(<<<'SQL'
            CREATE TABLE team_placement_department (
                placement_id INT NOT NULL,
                department_id INT NOT NULL,
                PRIMARY KEY(placement_id, department_id)
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_21A7EF1A2F966E9D ON team_placement_department (placement_id)');
        $this->addSql('CREATE INDEX IDX_21A7EF1AAE80F5DF ON team_placement_department (department_id)');
        $this->addSql('ALTER TABLE team_placement_department ADD CONSTRAINT fk_team_placement_dept_placement FOREIGN KEY (placement_id) REFERENCES team_placement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE team_placement_department ADD CONSTRAINT fk_team_placement_dept_department FOREIGN KEY (department_id) REFERENCES team_department (id) ON DELETE CASCADE');
    }
}
