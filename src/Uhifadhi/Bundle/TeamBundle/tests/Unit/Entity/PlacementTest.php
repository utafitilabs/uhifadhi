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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Entity;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Tests\Unit\Entity\Fixtures\StubArea;

/**
 * WHERE ONE PERSON IS PLACED, and both of its dimensions.
 *
 * The placement is the half of every permission check that belongs to the
 * person rather than to their position, so what it answers is worth holding
 * here, away from a kernel: it is pure enough to specify in one file, and the
 * two questions it answers - does this cover the area, does this cover the
 * department - are the second and third of the three a check asks.
 *
 * THE ONE THAT MATTERS MOST IS THE LAST: a placement that names nothing
 * reaches nothing. "Everywhere" has to be something somebody decided and
 * wrote down, because the alternative is a row whose area set failed to save
 * reading as organization-wide.
 */
final class PlacementTest extends TestCase
{
    public function testAFreshPlacementReachesNothingAtAll(): void
    {
        $placement = new Placement();

        self::assertTrue($placement->reachesNothing());
        self::assertFalse($placement->coversArea(new StubArea(1, 'kilimani')));
        self::assertFalse($placement->coversArea(null));
        self::assertSame([], $placement->getAreas());
    }

    public function testAcrossTheOrganizationCoversEveryAreaIncludingOneNobodyNamed(): void
    {
        $placement = new Placement()->acrossTheOrganization()->inDepartment(new Department()->setName('Protection Service'));

        self::assertTrue($placement->isWholeOrganization());
        self::assertNull($placement->getAreas(), 'null is the ruled shape for "the whole organization", and it cannot be confused with "nothing was named".');
        self::assertTrue($placement->coversArea(new StubArea(7, 'gazetted-last-week')));
        self::assertFalse($placement->reachesNothing());
    }

    public function testNamedGroundCoversWhatItNamesAndRefusesWhatItDoesNot(): void
    {
        $kilimani = new StubArea(1, 'kilimani');
        $olkeju = new StubArea(2, 'olkeju');
        $mbuyu = new StubArea(3, 'mbuyu');

        $placement = new Placement()->inAreas([$kilimani, $olkeju]);

        self::assertFalse($placement->isWholeOrganization());
        self::assertSame([$kilimani, $olkeju], $placement->getAreas());
        self::assertTrue($placement->coversArea($kilimani));
        self::assertTrue($placement->coversArea($olkeju));
        self::assertFalse($placement->coversArea($mbuyu), 'the worked example in the ruling: the right position in the wrong area is refused.');
    }

    /**
     * The same area, a different instance - which is what happens the moment
     * one side came out of a query and the other out of a route.
     */
    public function testAnAreaIsMatchedOnItsPublicAddressRatherThanOnIdentity(): void
    {
        $placement = new Placement()->inAreas([new StubArea(1, 'kilimani')]);

        self::assertTrue($placement->coversArea(new StubArea(1, 'kilimani')));
    }

    /**
     * A NULL AREA IS "NO AREA IN CONTEXT" - a nav question, a "may I ever?"
     * flag. Somebody placed somewhere is asked again once an area is named.
     */
    public function testNoAreaInContextIsCoveredByAnybodyPlacedAtAll(): void
    {
        self::assertTrue(new Placement()->inAreas([new StubArea(1, 'kilimani')])->coversArea(null));
        self::assertTrue(new Placement()->acrossTheOrganization()->coversArea(null));
        self::assertFalse(new Placement()->coversArea(null), 'unplaced is not "everywhere": the model fails closed.');
    }

    public function testEmptyGroundIsRefusedBecauseItIsNotAWayOfSayingAll(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/names at least one/');

        new Placement()->inAreas([]);
    }

    // --- the department and what it supports ----------------------------

    /**
     * ONE DEPARTMENT EACH, ANY NUMBER SUPPORTED (ruled 2 Oct 2026). A ranger
     * belongs to Protection Service and may support Ecology without belonging
     * to it: belonging is the reporting line, serving is where their work and
     * their modules reach.
     */
    public function testAPlacementBelongsToOneDepartmentAndServesTheOnesItSupports(): void
    {
        $protection = new Department()->setName('Protection Service');
        $ecology = new Department()->setName('Ecology');
        $ict = new Department()->setName('ICT');

        $placement = new Placement()->acrossTheOrganization()->inDepartment($protection)->supporting([$ecology]);

        self::assertSame($protection, $placement->getDepartment());
        self::assertSame([$ecology], $placement->getSupports());
        self::assertTrue($placement->belongsTo($protection));
        self::assertFalse($placement->belongsTo($ecology), 'supporting is not belonging.');
        self::assertTrue($placement->serves($protection));
        self::assertTrue($placement->serves($ecology));
        self::assertFalse($placement->serves($ict));
    }

    /** Their own department is never also on the list they support. */
    public function testTheirOwnDepartmentIsNeverAlsoSupported(): void
    {
        $protection = new Department()->setName('Protection Service');
        $ecology = new Department()->setName('Ecology');

        $placement = new Placement()->inDepartment($protection)->supporting([$protection, $ecology]);
        self::assertSame([$ecology], $placement->getSupports());

        $placement->inDepartment($ecology);
        self::assertSame([], $placement->getSupports(), 'moving into the department they supported ends the support.');
    }

    /**
     * The department question is CONDITIONAL: when the concern belongs to no
     * department, it does not arise, and a question that does not arise is
     * not a refusal.
     */
    public function testAConcernBelongingToNoDepartmentIsNotRefusedByTheDepartmentQuestion(): void
    {
        self::assertTrue(new Placement()->serves(null));
    }

    /**
     * NO DEPARTMENT IS AN UNFINISHED RECORD, and it fails closed: the person
     * belongs nowhere, serves nothing and reaches nothing, whatever ground
     * they were given.
     */
    public function testWithoutADepartmentItServesNothingAndReachesNothing(): void
    {
        $placement = new Placement()->acrossTheOrganization();

        self::assertNull($placement->getDepartment());
        self::assertFalse($placement->serves(new Department()->setName('Ecology')));
        self::assertTrue($placement->reachesNothing());
    }

    public function testChoosingOneBreadthClearsTheOther(): void
    {
        $kilimani = new StubArea(1, 'kilimani');

        $placement = new Placement()->inAreas([$kilimani])->acrossTheOrganization();
        self::assertNull($placement->getAreas(), 'saying "everywhere" after naming ground drops the ground, rather than keeping a list nothing reads.');

        $placement->inAreas([$kilimani]);
        self::assertFalse($placement->isWholeOrganization());
    }

    // --- the fragment ----------------------------------------------------

    public function testTheDepartmentFragmentNamesTheirsAndWhatTheySupport(): void
    {
        $protection = new Department()->setName('Protection Service');
        $ecology = new Department()->setName('Ecology');
        $ict = new Department()->setName('ICT');

        self::assertNull(new Placement()->departmentsLabel(), 'in none.');
        self::assertSame('Protection Service', new Placement()->inDepartment($protection)->departmentsLabel());
        self::assertSame('Protection Service · supports Ecology', new Placement()->inDepartment($protection)->supporting([$ecology])->departmentsLabel());
        self::assertSame('Protection Service · supports Ecology +1', new Placement()->inDepartment($protection)->supporting([$ecology, $ict])->departmentsLabel());
    }
}
