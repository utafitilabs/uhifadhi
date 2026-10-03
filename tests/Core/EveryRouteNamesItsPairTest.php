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
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Uhifadhi\Bundle\AreaBundle\Security\CheckInWriteVoter;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Security\MemberVoter;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Core\Tests\Application\Kernel;
use Uhifadhi\Testing\GateReader;

/**
 * A DECLARED POWER AND AN ENFORCED ONE CANNOT DRIFT APART.
 *
 * This is the first of the four proofs the permission model rests on, and it
 * walks the router in both directions:
 *
 *   1. EVERY ROUTE NAMES A PAIR. A route with no gate is a page anybody
 *      signed in can open, which is sometimes right and must therefore be
 *      SAID rather than left to be inferred from an absence — so the ones
 *      that are deliberately open are listed here by name, and a new route
 *      that names nothing fails until somebody decides which it is.
 *   2. EVERY PAIR A ROUTE NAMES IS ONE SOMEBODY DECLARED. A gate on a pair
 *      no concern offers can never open: the voter abstains, nothing else
 *      grants it, and the page is dead with nothing saying why. A typo in a
 *      verb is exactly this, and it is invisible in review.
 *   3. EVERY DECLARED PAIR IS ENFORCED SOMEWHERE. A row in the matrix that
 *      nothing checks is a box an administrator can tick that changes
 *      nothing — a promise the product does not keep. Enforcement counts
 *      whether it is a route's gate or a door in a template, because both
 *      are places the pair does work.
 *   4. EVERY QUESTION HAS EXACTLY ONE VOTER. None is a page shut for
 *      everybody. Two is a rule that can be outvoted: under the affirmative
 *      strategy one voter's yes is enough, so a narrower voter added beside
 *      the grant voter — "only the recorder may change this" — would never
 *      count. A voter says which questions it answers through
 *      `supportsAttribute()`; one that does not claims every question.
 *
 * It is a BUILD test rather than a review convention because all three
 * failures look like working code.
 */
#[CoversNothing]
final class EveryRouteNamesItsPairTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // The framework's debug exception handler is registered while a kernel
        // boots and never popped, which PHPUnit reports as a risky test.
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    /**
     * THE ROUTES THAT ARE DELIBERATELY OPEN, each with the reason. Signing
     * in, recovering a password and accepting an invitation cannot require
     * being signed in, and the API's token endpoint is how a handset becomes
     * anybody at all.
     *
     * @var array<string, string>
     */
    private const array OPEN = [
        'team_login' => 'signing in cannot require being signed in',
        'team_logout' => 'the firewall answers this one; no controller runs',
        'team_reset' => 'recovering a password is for somebody who cannot get in',
        'team_reset_request' => 'the same, the asking half',
        'team_reset_send' => 'the same, the sending half',
        'team_reset_submit' => 'the same, the setting half',
        'team_invite_accept' => 'an invited person has no account to hold a permission with yet',
        'team_invite_accept_submit' => 'the same, the submitting half',
        'team_api_auth_token' => 'this is how a handset becomes somebody',
        'welcome' => 'the landing page names no record and shows nobody anything they are not already entitled to',
        'my_dashboard' => "a person's own dashboard shows nothing but their own records (#19)",
        'me_station' => "a person's own station page shows nothing but the post they are posted at (#19)",
        'me_duty_log' => "a person's own duty log shows nothing but the check-ins they made (#19)",
        'team_profile' => "My profile shows nothing but the signed-in person's own account (#69)",
        'team_profile_details' => "the same, saving one's own name and phone",
        'team_profile_password' => 'the same, changing one\'s own password, which asks for the current one',
        'team_profile_email' => 'the same, asking for a new address, which changes nothing until its link is opened',
        'team_profile_email_confirm' => 'the same, the link sent to the new address, which only its own account can open',
        'team_profile_handset_sign_out' => "the same, signing out one's own phone; anybody else's answers 404",
        'liveness' => 'an uptime probe, answered out of the container alone, with no database and no session',
        'api_doc' => "API Platform's own documentation page",
        'api_entrypoint' => "API Platform's own index",
        'api_genid' => "API Platform's identifier resolver, which serves no record",
        '_api_errors' => "API Platform's error renderer",
        'api_validation_errors' => "API Platform's validation-error renderer",
        '_api_validation_errors_hydra' => 'the same, per format',
        '_api_validation_errors_jsonapi' => 'the same, per format',
        '_api_validation_errors_problem' => 'the same, per format',
        '_api_validation_errors_xml' => 'the same, per format',
    ];

    /**
     * ROUTES WHOSE GATE IS SOMEWHERE THIS TEST CANNOT READ, each with where
     * it actually is. They are listed rather than skipped so the gap is a
     * fact on the page instead of an absence nobody notices.
     *
     * THE HANDSET'S ENDPOINTS ARE API PLATFORM OPERATIONS, so their
     * `_controller` is API Platform's generic one and the gate lives in the
     * state provider or processor behind it. Reading those the way this
     * reads a controller means walking the resource metadata, which is worth
     * doing and is not done here — so until it is, each one names the class
     * that gates it, and a reviewer can check the two agree.
     *
     * THE SHELL'S CONFIGURE FRAME IS A GENUINE OPEN QUESTION, not an
     * omission. It renders a frame of sections each of which is contributed
     * by whoever owns it, and the shell holds no authorization service and
     * cannot know which concern a surface's sections belong to. Opening the
     * bare frame leaks the section NAMES of a surface; acting on any of them
     * is gated by the section's own screen. Whether the frame should carry a
     * gate of its own is a ruling nobody has made.
     *
     * @var array<string, string>
     */
    private const array GATED_ELSEWHERE = [
        'area_live_sheet' => 'AreaBundle\\Service\\LiveSheet — the person\'s area must be readable (areas.read) and the person within the viewer\'s live sight (LiveVisibility), as on every plate (#16 C)',
        '_api_/me_get' => 'TeamBundle\\Api\\State\\MeProvider',
        '_api_/areas/mine_get' => 'AreaBundle\\Api\\State\\AreasMineProvider::PERMISSION',
        '_api_/areas/{areaUuid}/stations_get' => 'AreaBundle\\Api — the duty context',
        '_api_/areas/{areaUuid}/me/roster_get' => 'AreaBundle\\Api — the duty context',
        '_api_/areas/{areaUuid}/checkins_post' => 'AreaBundle\\Api\\DutyApiContext::requireRanger',
        '_api_/areas/{areaUuid}/checkins/{clientRef}_patch' => 'AreaBundle\\Api\\DutyApiContext::requireRanger',
        '_api_/areas/{areaUuid}/positions_post' => 'AreaBundle\\Api\\DutyApiContext::requireRanger',
        'organization_dashboard' => 'AreaBundle\\Controller\\OrgDashboardController::dashboard — dashboard.read decides WHICH dashboard `/` draws (the organization\'s, or the person\'s own, #19), never whether there is one',
        'shell_area_configure' => 'each section it frames, on its own screen — and whether the frame itself should be gated is unruled',
        'shell_module_configure' => 'the same, for a module surface',
        'team_member_delete' => 'TeamBundle\\Deletion\\DeletionService — a Super Admin by tier, never a pair (ruled 28 Sep, #48)',
        'team_position_delete' => 'TeamBundle\\Deletion\\DeletionService — a Super Admin by tier, never a pair (ruled 28 Sep, #48)',
        'team_department_delete' => 'TeamBundle\\Deletion\\DeletionService — a Super Admin by tier, never a pair (ruled 28 Sep, #48)',
        'area_station_delete' => 'TeamBundle\\Deletion\\DeletionService — a Super Admin by tier, never a pair (ruled 28 Sep, #48)',
        'area_delete' => 'TeamBundle\\Deletion\\DeletionService — a Super Admin by tier, never a pair (ruled 28 Sep, #48)',
        'team_settings_deletions' => 'TeamBundle\\Deletion\\DeletionService — a Super Admin by tier, never a pair (ruled 28 Sep, #48)',
        'settings' => 'ShellBundle\\Controller\\SettingsController — settings.read, asked by the controller for every screen of the section',
    ];

    public function testEveryRouteEitherNamesAPairOrIsDeliberatelyOpen(): void
    {
        $nameless = [];

        foreach ($this->appRoutes() as $name => $route) {
            if (isset(self::OPEN[$name]) || isset(self::GATED_ELSEWHERE[$name])) {
                continue;
            }

            if ([] === GateReader::pairsOn($route)) {
                $nameless[] = $name.' ('.$route->getPath().')';
            }
        }

        sort($nameless);

        self::assertSame([], $nameless, \sprintf(
            "These routes name no (concern, verb) pair [%s].\n".
            'A route says what it enforces in its `#[IsGranted]` attribute, spelled `<concern>.<verb>`. '.
            'A route that is meant to be open to anybody signed in says so by being listed in this test '.
            "with its reason — the point is that somebody decided, not that nobody wrote it down.\n",
            implode(', ', $nameless),
        ));
    }

    public function testEveryPairARouteNamesIsOneSomebodyDeclared(): void
    {
        $catalogue = $this->catalogue();
        $undeclared = [];

        foreach ($this->appRoutes() as $name => $route) {
            foreach (GateReader::pairsOn($route) as $pair) {
                $grant = Grant::tryParse($pair);
                if (null === $grant || !$catalogue->has($grant)) {
                    $undeclared[] = $name.' -> '.$pair;
                }
            }
        }

        sort($undeclared);

        self::assertSame([], $undeclared, \sprintf(
            "These routes enforce a pair nothing declares [%s].\n".
            'The voter abstains on a pair no concern offers, nothing else grants it, and the page is dead '.
            'with nothing saying why. Either the concern should declare that verb, or the gate has a typo.',
            implode(', ', $undeclared),
        ));
    }

    /**
     * EVERY GATE ON A PER-AREA CONCERN IS ASKED WITH ITS AREA.
     *
     * `#[IsGranted('zones.read')]` with no `subject:` asks the voter with a
     * NULL subject, and a null subject means "no area in context" — which
     * any placement that reaches some ground at all satisfies. On a route
     * whose path names an area, that is the second of the three questions
     * being asked and always answered yes: somebody placed only at one area
     * opens another one's page.
     *
     * IT IS A REFUSAL RATHER THAN A LIST. The gap was held as thirteen route
     * names while it was being closed; a list of exceptions is a list that
     * grows, so what survives the closing is the rule — a new area-scoped
     * route that forgets its subject fails here, on the build, rather than
     * joining a register nobody reads.
     *
     * WHICH CONCERNS THIS IS ABOUT IS THE DECLARATION'S ANSWER, not a
     * spelling of the path. A concern is per-area when it offers
     * {@see ScopeKind::Area} — ground, zones, stations, assignments, duty,
     * an area's modules. Departments and the directory are about people and
     * offer organization or department instead, so a departments page inside
     * an area is NOT gated on the ground and must not be made to look as
     * though it is.
     *
     * IT READS THE ATTRIBUTE, not the source text, because the subject is
     * what this is about and a regex over an argument list would be reading
     * the spelling rather than the value. A gate written in code passes its
     * subject as an argument and is out of this test's reach; the ones this
     * model is about are all attributes.
     */
    public function testEveryRouteGatingAPerAreaConcernPassesItsArea(): void
    {
        $catalogue = $this->catalogue();
        $blind = [];

        foreach ($this->appRoutes() as $name => $route) {
            $path = $route->getPath();
            if (!str_starts_with($path, '/areas/') || !str_contains($path, '{uuid}')) {
                continue;
            }

            foreach (self::attributeGatesOn($route) as $gate) {
                if (!\is_string($gate->attribute) || null !== $gate->subject) {
                    continue;
                }

                if (true === $catalogue->concern(explode('.', $gate->attribute)[0])?->offers(ScopeKind::Area)) {
                    $blind[] = $name.' -> '.$gate->attribute;
                }
            }
        }

        sort($blind);

        self::assertSame([], $blind, \sprintf(
            "These routes name an area in their path and ask a per-area pair without it [%s].\n".
            'Pass the area: `#[IsGranted(\'<pair>\', subject: \'area\')]`, with the controller taking the '.
            'resolved area rather than a bare uuid. Without it the placement question is asked with no '.
            'ground and is always answered yes.',
            implode(', ', $blind),
        ));
    }

    public function testEveryDeclaredPairIsEnforcedSomewhere(): void
    {
        $enforced = [];

        // EVERY GATE IN EVERY SHIPPED CLASS, not only the ones a route leads
        // to. A handset endpoint is an API Platform operation whose gate is
        // in its state provider, and a sidebar row is gated in a navigation
        // source; both are places a pair does real work, and a test that
        // counted only routed controller methods would call them idle.
        foreach (self::shippedSource() as $path) {
            foreach (GateReader::pairsInFile($path) as $pair) {
                $enforced[$pair] = true;
            }
        }

        foreach (self::doorPairs() as $pair) {
            $enforced[$pair] = true;
        }

        $idle = array_values(array_filter(
            $this->catalogue()->pairs(),
            static fn (string $pair): bool => !isset($enforced[$pair]),
        ));

        sort($idle);

        self::assertSame([], $idle, \sprintf(
            "These pairs are declared and nothing enforces them [%s].\n".
            'A row in the matrix that no route gates and no door asks about is a box an administrator can '.
            'tick that changes nothing. Either something should enforce it, or the concern should stop '.
            'declaring that verb until something does.',
            implode(', ', $idle),
        ));
    }

    public function testEveryPairARouteOrADoorAsksIsAnsweredByExactlyOneVoter(): void
    {
        $asked = ['a door in a template' => self::doorPairs()];
        foreach ($this->appRoutes() as $name => $route) {
            $asked[$name] = GateReader::pairsOn($route);
        }

        $wrong = [];
        foreach ($asked as $where => $attributes) {
            foreach ($attributes as $attribute) {
                $claimants = $this->claimants($attribute);
                if (1 !== \count($claimants)) {
                    $wrong[] = \sprintf('%s asks "%s", %s', $where, $attribute, self::describe($claimants));
                }
            }
        }

        self::assertSame([], $wrong, implode("\n", $wrong));
    }

    /**
     * Not only what a route asks today: every declared pair, and every
     * question a voter of the core answers by its own rule, so a page added
     * tomorrow cannot meet a question with two answers.
     */
    public function testEveryDeclaredPairAndEveryOwnRuleIsAnsweredByExactlyOneVoter(): void
    {
        $wrong = [];
        foreach ([...$this->catalogue()->pairs(), MemberVoter::CONFIGURE, CheckInWriteVoter::WRITE] as $attribute) {
            $claimants = $this->claimants($attribute);
            if (1 !== \count($claimants)) {
                $wrong[] = \sprintf('"%s": %s', $attribute, self::describe($claimants));
            }
        }

        self::assertSame([], $wrong, implode("\n", $wrong));
    }

    /** A question nothing declares has no voter, so the decision manager refuses it. */
    public function testAQuestionNothingDeclaresIsAnsweredByNoVoter(): void
    {
        self::assertSame([], $this->claimants('zones.confgure'));
        self::assertSame([], $this->claimants('nothing-declares-this.read'));
    }

    /**
     * The voters that claim a question. A voter that does not say which
     * questions it answers claims every one, because the decision manager
     * asks it every time.
     *
     * @return list<string>
     *
     * @see vendor/symfony/security-core/Authorization/AccessDecisionManager.php — getVoters(): a voter that is not a CacheableVoterInterface is always asked; one that is, only where supportsAttribute() is true
     */
    private function claimants(string $attribute): array
    {
        self::bootKernel();
        $voters = self::getContainer()->get('test_public.security.voters');
        self::assertInstanceOf(InstalledVoters::class, $voters);

        $claimants = [];
        foreach ($voters->all() as $voter) {
            if (!$voter instanceof CacheableVoterInterface || $voter->supportsAttribute($attribute)) {
                $claimants[] = $voter::class;
            }
        }

        return $claimants;
    }

    /**
     * @param list<string> $claimants
     */
    private static function describe(array $claimants): string
    {
        return [] === $claimants ? 'which no voter answers' : 'which several voters answer: '.implode(', ', $claimants);
    }

    /**
     * The `#[IsGranted]` attributes on the controller method a route leads
     * to, as instances — so the subject is read as a value.
     *
     * @return list<IsGranted>
     */
    private static function attributeGatesOn(Route $route): array
    {
        $controller = $route->getDefault('_controller');
        if (!\is_string($controller) || !str_contains($controller, '::')) {
            return [];
        }

        [$class, $method] = explode('::', $controller, 2);
        if (!class_exists($class) || !method_exists($class, $method)) {
            return [];
        }

        $gates = [];
        foreach (new \ReflectionMethod($class, $method)->getAttributes(IsGranted::class) as $attribute) {
            $gates[] = $attribute->newInstance();
        }

        // A CLASS-LEVEL GATE COVERS EVERY METHOD UNDER IT, so it is as much
        // this route's gate as one written above the signature.
        foreach (new \ReflectionClass($class)->getAttributes(IsGranted::class) as $attribute) {
            $gates[] = $attribute->newInstance();
        }

        return $gates;
    }

    /**
     * Every PHP file the core ships, so a gate can be found wherever it is
     * written.
     *
     * @return list<string>
     */
    private static function shippedSource(): array
    {
        $paths = [];

        foreach (glob(\dirname(__DIR__, 2).'/src/Uhifadhi/Bundle/*', \GLOB_ONLYDIR) ?: [] as $bundle) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($bundle, \FilesystemIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                // A gate inside a TEST is a fixture, not an enforcement.
                if ('php' === $file->getExtension() && !str_contains($file->getPathname(), '/tests/')) {
                    $paths[] = $file->getPathname();
                }
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * Every pair a door asks about, read from the shipped templates.
     *
     * @return list<string>
     */
    private static function doorPairs(): array
    {
        $pairs = [];

        foreach (self::shippedTemplates() as $path) {
            $markup = (string) file_get_contents($path);
            preg_match_all("/door\\(\\s*'([a-z0-9-]+\\.[a-z]+)'/", $markup, $matches);
            foreach ($matches[1] as $pair) {
                $pairs[$pair] = true;
            }
        }

        return array_keys($pairs);
    }

    private function catalogue(): ConcernCatalogue
    {
        self::bootKernel();
        $catalogue = self::getContainer()->get('test_public.'.ConcernCatalogue::class);
        self::assertInstanceOf(ConcernCatalogue::class, $catalogue);

        return $catalogue;
    }

    /**
     * Every route this installation mounts, by name — the same collection a
     * request is matched against, so nothing this walks is hypothetical.
     *
     * @return array<string, Route>
     */
    private function appRoutes(): array
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router->getRouteCollection()->all();
    }

    /**
     * The Twig files the core ships. A door lives in a template, so this is
     * where the second half of the enforcement question is answered.
     *
     * @return list<string>
     */
    private static function shippedTemplates(): array
    {
        $paths = [];
        foreach (glob(\dirname(__DIR__, 2).'/src/Uhifadhi/Bundle/*/templates', \GLOB_ONLYDIR) ?: [] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ('twig' === $file->getExtension()) {
                    $paths[] = $file->getPathname();
                }
            }
        }

        sort($paths);

        return $paths;
    }
}
