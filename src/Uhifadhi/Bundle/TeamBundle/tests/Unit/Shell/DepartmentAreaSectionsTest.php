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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Shell;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\TeamBundle\Access\Door;
use Uhifadhi\Bundle\TeamBundle\Controller\AreaDepartmentController;
use Uhifadhi\Bundle\TeamBundle\Shell\DepartmentAreaSections;

/**
 * The entry this bundle puts on an area's configure strip: one screen, at the
 * section's own address, for the area it was asked about.
 */
#[CoversClass(DepartmentAreaSections::class)]
final class DepartmentAreaSectionsTest extends TestCase
{
    public function testItContributesTheDepartmentsScreenForThatArea(): void
    {
        $sections = (new DepartmentAreaSections(self::doorHolding(['departments.read'])))->sectionsFor('0198f0a0-0000-7000-8000-000000000001', 'Northreach');

        self::assertCount(1, $sections);
        self::assertSame('departments', $sections[0]->id);
        self::assertSame('Departments', $sections[0]->label);
        self::assertSame(AreaDepartmentController::SECTION, $sections[0]->routeName);
        self::assertSame(['uuid' => '0198f0a0-0000-7000-8000-000000000001'], $sections[0]->parameters);
    }

    /** The screen enforces `departments.read`; a viewer without it is offered no entry. */
    public function testAViewerWithoutDepartmentsReadIsOfferedNothing(): void
    {
        self::assertSame([], (new DepartmentAreaSections(self::doorHolding([])))->sectionsFor('0198f0a0-0000-7000-8000-000000000001', 'Northreach'));
    }

    /** @param list<string> $held */
    private static function doorHolding(array $held): Door
    {
        return new Door(new readonly class($held) implements AuthorizationCheckerInterface {
            /** @param list<string> $held */
            public function __construct(private array $held)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return \in_array($attribute, $this->held, true);
            }
        });
    }
}
