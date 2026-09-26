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

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Access\AreaConcerns;
use Uhifadhi\Bundle\RegistryBundle\Access\RegistryConcerns;
use Uhifadhi\Bundle\TeamBundle\Access\TeamConcerns;
use Uhifadhi\Contracts\Access\ConcernInterface;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Access\ScopeKind;

/**
 * THE CORE DECLARES ITS OWN CONCERNS THE WAY A MODULE DOES.
 *
 * There is no privileged list in the middle of the product: the ground's
 * concerns arrive from the area bundle, people's from the team's, the
 * catalogue's from the registry, each through the same tagged seam a module
 * uses. This test holds that they are there, that they are spelled once,
 * and that they obey the scope ruling - ground is organization or area
 * because ground is where it is; team concerns are organization or
 * department.
 */
#[CoversNothing]
final class TheCoreDeclaresItsConcernsTest extends TestCase
{
    /** @return list<ConcernSourceInterface> */
    private static function sources(): array
    {
        return [new AreaConcerns(), new TeamConcerns(), new RegistryConcerns()];
    }

    public function testTheGroundDeclaresAreasZonesStationsAssignmentsDutyAndLocations(): void
    {
        self::assertSame(
            ['areas', 'zones', 'stations', 'assignments', 'duty', 'locations'],
            array_map(static fn (ConcernInterface $c): string => $c->key(), self::iterate(new AreaConcerns())),
        );
    }

    public function testTheTeamDeclaresTheDirectoryPersonalDetailsPositionsDepartmentsAndRanks(): void
    {
        self::assertSame(
            ['directory', 'personal-details', 'positions', 'departments', 'ranks'],
            array_map(static fn (ConcernInterface $c): string => $c->key(), self::iterate(new TeamConcerns())),
        );
    }

    public function testTheRegistryDeclaresModules(): void
    {
        self::assertSame(
            ['modules'],
            array_map(static fn (ConcernInterface $c): string => $c->key(), self::iterate(new RegistryConcerns())),
        );
    }

    public function testPersonalDetailsAreSensitiveAndTheDirectoryIsNot(): void
    {
        $team = self::byKey(new TeamConcerns());

        self::assertTrue($team['personal-details']->isSensitive(), 'Contact details, sign-in and employment are withheld without withholding the directory.');
        self::assertFalse($team['directory']->isSensitive());
    }

    public function testGroundConcernsOfferOrganizationOrArea(): void
    {
        foreach (self::iterate(new AreaConcerns()) as $concern) {
            self::assertSame(
                [ScopeKind::Organization, ScopeKind::Area],
                $concern->scopeKinds(),
                \sprintf('The ground concern "%s" offers a scope the ruling does not give it.', $concern->key()),
            );
        }
    }

    public function testTeamConcernsOfferOrganizationOrDepartment(): void
    {
        foreach (self::iterate(new TeamConcerns()) as $concern) {
            self::assertSame(
                [ScopeKind::Organization, ScopeKind::Department],
                $concern->scopeKinds(),
                \sprintf('The team concern "%s" offers a scope the ruling does not give it.', $concern->key()),
            );
        }
    }

    public function testNoCoreConcernClaimsAModule(): void
    {
        foreach (self::sources() as $source) {
            foreach (self::iterate($source) as $concern) {
                self::assertNull($concern->moduleSlug(), \sprintf('"%s" is a core bundle\'s concern and belongs to no module.', $concern->key()));
            }
        }
    }

    public function testEveryCoreConcernKeyIsSpelledOnce(): void
    {
        $seen = [];
        foreach (self::sources() as $source) {
            foreach (self::iterate($source) as $concern) {
                self::assertArrayNotHasKey($concern->key(), $seen, \sprintf('"%s" is declared by both %s and %s.', $concern->key(), $seen[$concern->key()] ?? '', $source->declaredBy()));
                $seen[$concern->key()] = $source->declaredBy();
            }
        }

        self::assertCount(12, $seen);
    }

    public function testEverySourceSaysWhoItIs(): void
    {
        self::assertSame(['Areas', 'Team', 'Modules'], array_map(static fn (ConcernSourceInterface $s): string => $s->declaredBy(), self::sources()));
    }

    /**
     * The tag is not autoconfigured in a reusable bundle, so a source that is
     * written and never registered is a group of rows nobody can grant.
     */
    #[DataProvider('everyRegistration')]
    public function testEverySourceIsTaggedInItsBundle(string $servicesFile, string $class): void
    {
        $config = file_get_contents(\dirname(__DIR__, 2).'/'.$servicesFile);
        self::assertIsString($config);
        self::assertStringContainsString($class.'::class', $config);
        self::assertStringContainsString('ConcernSourceInterface::TAG', $config);
    }

    /** @return iterable<string, array{string, string}> */
    public static function everyRegistration(): iterable
    {
        yield 'area' => ['src/Uhifadhi/Bundle/AreaBundle/config/services.php', 'AreaConcerns'];
        yield 'team' => ['src/Uhifadhi/Bundle/TeamBundle/config/services.php', 'TeamConcerns'];
        yield 'registry' => ['src/Uhifadhi/Bundle/RegistryBundle/config/services.php', 'RegistryConcerns'];
    }

    /** @return list<ConcernInterface> */
    private static function iterate(ConcernSourceInterface $source): array
    {
        return array_values([...$source->concerns()]);
    }

    /** @return array<string, ConcernInterface> */
    private static function byKey(ConcernSourceInterface $source): array
    {
        $out = [];
        foreach (self::iterate($source) as $concern) {
            $out[$concern->key()] = $concern;
        }

        return $out;
    }
}
