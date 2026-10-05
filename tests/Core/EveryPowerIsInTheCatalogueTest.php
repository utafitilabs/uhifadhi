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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Permissions\PowerCatalogue;
use Uhifadhi\Bundle\TeamBundle\Security\MemberVoter;
use Uhifadhi\Bundle\TeamBundle\Security\SignInHelpVoter;
use Uhifadhi\Contracts\Access\PowerGroup;
use Uhifadhi\Core\Tests\Application\Kernel;
use Uhifadhi\Test\GateReader;

/**
 * THE PERMISSIONS PAGE NAMES EVERY POWER. It lists the actions that confer
 * power — gaining it, giving it, acting on somebody else's records, taking an
 * account over — and asks each as every kind of person. A route that confers
 * power with a question the catalogue does not ask would be a power the page
 * never shows, so the build fails on it.
 */
#[CoversNothing]
final class EveryPowerIsInTheCatalogueTest extends KernelTestCase
{
    /** The questions that confer power beyond the tier-only pairs, which the catalogue reads itself. */
    private const array POWER_QUESTIONS = [
        SignInHelpVoter::PAIR,
        SignInHelpVoter::HELP,
        MemberVoter::TIER,
        MemberVoter::ONE_TIME_PASSWORD,
    ];

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testTheCoreDeclaresItsPowersInEveryGroup(): void
    {
        $powers = $this->powers()->all();

        $keys = array_map(static fn ($power): string => $power->key, $powers);
        self::assertSame($keys, array_values(array_unique($keys)), 'every power has its own key');
        self::assertGreaterThanOrEqual(23, \count($powers));
        foreach (PowerGroup::cases() as $group) {
            self::assertNotSame([], array_filter($powers, static fn ($power): bool => $power->group === $group), $group->label());
        }
        foreach ($powers as $power) {
            self::assertNotSame([], $power->targets, $power->key.' is asked about somebody or something');
            self::assertNotSame([], $power->questions, $power->key.' asks at least one question');
        }
    }

    public function testEveryQuestionIsOneAnInstalledVoterAnswers(): void
    {
        $catalogue = self::getContainer()->get('test_public.'.ConcernCatalogue::class);
        self::assertInstanceOf(ConcernCatalogue::class, $catalogue);
        $voters = self::getContainer()->get('test_public.security.voters');
        self::assertInstanceOf(InstalledVoters::class, $voters);

        $pairs = $catalogue->pairs();
        $unanswered = [];
        foreach ($this->powers()->questions() as $question) {
            if (\in_array($question, $pairs, true) || str_starts_with($question, 'ROLE_')) {
                continue;
            }
            $answering = array_filter($voters->all(), static fn ($voter): bool => method_exists($voter, 'supportsAttribute') && $voter->supportsAttribute($question));
            if ([] === $answering) {
                $unanswered[] = $question;
            }
        }

        self::assertSame([], $unanswered, 'a question no installed voter answers would read refused for everybody');
    }

    public function testEveryRouteThatConfersPowerAsksAQuestionTheCatalogueAsks(): void
    {
        $catalogue = self::getContainer()->get('test_public.'.ConcernCatalogue::class);
        self::assertInstanceOf(ConcernCatalogue::class, $catalogue);
        $conferring = [...self::POWER_QUESTIONS, ...array_values(array_filter($catalogue->pairs(), $catalogue->isTierOnly(...)))];
        $asked = $this->powers()->questions();

        $missing = [];
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            foreach (GateReader::pairsOn($route) as $question) {
                if (\in_array($question, $conferring, true) && !\in_array($question, $asked, true)) {
                    $missing[] = $name.' asks '.$question;
                }
            }
        }

        self::assertSame([], $missing, 'these routes confer power the Permissions page would not show');
    }

    private function powers(): PowerCatalogue
    {
        $powers = self::getContainer()->get('test_public.team.permissions.powers');
        self::assertInstanceOf(PowerCatalogue::class, $powers);

        return $powers;
    }
}
