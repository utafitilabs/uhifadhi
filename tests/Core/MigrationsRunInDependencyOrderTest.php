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

namespace Uhifadhi\Core\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\RegistryBundle\Version\DependencyOrderComparator;
use Uhifadhi\Core\Tests\Application\Kernel;

/**
 * THE ORDER, ASKED OF A BOOTED INSTALLATION rather than of the comparator.
 *
 * The unit specification states the rule against a fixture graph. This one
 * states the consequence an installer meets: a version an installation writes
 * for its OWN entities runs after every version a package ships, whatever date
 * it carries — and it carries an early one here, early enough that a date-
 * ordered plan would put it in front of the tables its own entities point at.
 *
 * The date is the only thing separating this from the version a flagless
 * `doctrine:migrations:diff` writes, so the file is written by hand: nothing
 * generated can be given a timestamp from before the core's.
 */
#[CoversClass(DependencyOrderComparator::class)]
final class MigrationsRunInDependencyOrderTest extends MigrationsTestCase
{
    private const string INSTALLATION_VERSION = 'DoctrineMigrations\\Version20100101000000';

    private string $fixture = '';

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = self::$kernel;
        self::assertInstanceOf(Kernel::class, $kernel);

        $this->fixture = $kernel->installationMigrationsDir().'/Version20100101000000.php';

        file_put_contents($this->fixture, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace DoctrineMigrations;

            use Doctrine\DBAL\Schema\Schema;
            use Doctrine\Migrations\AbstractMigration;

            final class Version20100101000000 extends AbstractMigration
            {
                public function up(Schema $schema): void
                {
                }

                public function down(Schema $schema): void
                {
                }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        if ('' !== $this->fixture && is_file($this->fixture)) {
            unlink($this->fixture);
        }

        parent::tearDown();
    }

    public function testTheInstallationsOwnVersionRunsLastThoughItsDateIsTheEarliest(): void
    {
        $plan = $this->plannedVersions();

        self::assertContains(self::INSTALLATION_VERSION, $plan);
        self::assertSame(self::INSTALLATION_VERSION, end($plan));
    }

    /**
     * The core is one Composer package and one install path, so nothing between
     * its bundles is a dependency question and the timestamp decides — which
     * leaves the order its foreign keys demand exactly where it was.
     */
    public function testThePackagesVersionsKeepTheOrderTheirForeignKeysDemand(): void
    {
        self::assertSame(
            [
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260101000000',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260101000100',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260101000110',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260101000120',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260101000130',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260101000140',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260101000160',
                'Uhifadhi\\Bundle\\RegistryBundle\\Migrations\\Version20260101000200',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260101000300',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260101000310',
                'Uhifadhi\\Bundle\\ShellBundle\\Migrations\\Version20260101000400',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260101000500',
                'Uhifadhi\\Bundle\\RegistryBundle\\Migrations\\Version20260919000100',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260920000100',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260920000200',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260920000300',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260921000100',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260921000200',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260921000300',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260921000400',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260921000500',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260921001000',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260921002000',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260921003000',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260921004000',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260922000100',
                'Uhifadhi\\Bundle\\RegistryBundle\\Migrations\\Version20260924000100',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260924000100',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260924000200',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260925000100',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260925120000',
                'Uhifadhi\\Bundle\\RegistryBundle\\Migrations\\Version20260925180000',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260925200000',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260925220000',
                self::INSTALLATION_VERSION,
            ],
            $this->plannedVersions(),
        );
    }
}
