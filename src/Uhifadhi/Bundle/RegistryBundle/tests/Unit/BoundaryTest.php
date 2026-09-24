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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE BOUNDARIES, ENFORCED BY A SWEEP OF THE SHIPPED SOURCE.
 *
 * These are cheap, crude tests that read the shipped source as text, and they
 * are the only kind that can catch what they catch: "just this one reference,
 * for now" is how a runtime acquires a dependency on a module, and no type
 * system objects to it.
 *
 * Four rules:
 *
 *  1. The registry names no module. Not a slug, not a namespace. Everything it
 *     treats specially is a flag a provider declares.
 *  2. The registry names no host. It is installed BY an application; it does not
 *     reach back into one.
 *  3. The registry renders nothing. See docs/boundaries.md: the module grid, the
 *     customize screen and every tile is the shell's.
 *  4. The registry ships ONE console command, `registry:sync`, and names the
 *     console component nowhere else. Devkit owns every other command the
 *     platform has bar the team bundle's two; a second command here is a
 *     seeder that belongs to devkit.
 */
final class BoundaryTest extends TestCase
{
    /** The bundle root. Everything the package ships, minus its own suite. */
    private const string BUNDLE = __DIR__.'/../..';

    /**
     * Real modules, plus the two the platform is likeliest to smuggle in:
     * "overview" (the pinned hub — pinned is a flag, not a slug the registry
     * knows) and "map" (infrastructure — the registry must not know it by name
     * any more than it knew it as a base module).
     *
     * @return \Generator<string, array{non-empty-string}>
     */
    public static function moduleNames(): \Generator
    {
        foreach (['patrol', 'incident', 'roster', 'ingestion', 'storage', 'workflow', 'uhakiki', 'forest', 'overview', 'map'] as $name) {
            yield $name => [$name];
        }
    }

    /**
     * @param non-empty-string $name
     */
    #[DataProvider('moduleNames')]
    public function testTheRegistryKnowsNoModuleByName(string $name): void
    {
        $offenders = [];
        foreach (self::sources() as $path => $code) {
            // A backslash on either side means the word is a namespace segment
            // of somebody else's FQCN (Symfony's Token\Storage\, for one), not a
            // module this bundle named.
            if (1 === preg_match('/(?<![\\\\\w])'.preg_quote($name, '/').'(?![\\\\\w])/i', $code)) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, \sprintf(
            'The registry must not name the "%s" module. A module is whatever tagged itself; '
            .'pinned and base are flags a provider declares, never slugs the runtime recognises.',
            $name,
        ));
    }

    /**
     * The impersonatable HOST TREE. Every subtree an application owns, under
     * either root a host may carry: the product host is `Uhifadhi\`, a project
     * installed from the skeleton is stock-Symfony `App\`. These are exactly
     * the FQCNs a test stub is allowed to impersonate
     * (tests/Integration/Fixtures/Uhifadhi/…) and exactly the ones the shipped
     * runtime must never name.
     *
     * The rule is about the SUBTREE, not the root: `Uhifadhi\Bundle\…` is the
     * core's own, `Uhifadhi\Service\…` is the host's, and so is `App\Service\…`
     * in an installed project. The narrowing is a real loss of reach (a host
     * tree not on the list slips through) traded for a rule that is true; the
     * list is cheap to extend when a host grows a tree.
     *
     * @return \Generator<string, array{non-empty-string}>
     */
    public static function hostNamespaces(): \Generator
    {
        foreach (['Uhifadhi', 'App'] as $root) {
            foreach (['Entity', 'Service', 'Repository', 'Controller', 'Module', 'Overview'] as $tree) {
                $fqcn = $root.'\\'.$tree;
                yield $fqcn => [$fqcn];
            }
        }
    }

    /**
     * @param non-empty-string $namespace
     */
    #[DataProvider('hostNamespaces')]
    public function testTheRegistryReachesIntoNoHostApplication(string $namespace): void
    {
        $offenders = [];
        foreach (self::sources() as $path => $code) {
            if (str_contains($code, $namespace.'\\')) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, \sprintf(
            'The registry must not depend on the host application namespace "%s\\".',
            $namespace,
        ));
    }

    /**
     * THE REGISTRY NAMES NO SIBLING BUNDLE'S CLASS, and the one that matters
     * most is the bundle that keeps people.
     *
     * The registry decides authority — who a presented credential names, and
     * which permissions exist — and it must decide it about a CONTRACT, never
     * about somebody's account entity. The day it imports that class, an
     * installation cannot answer the question with its own people, and the two
     * packages have become one.
     *
     * @param non-empty-string $namespace
     */
    #[DataProvider('siblingNamespaces')]
    public function testTheRegistryNamesNoSiblingBundle(string $namespace): void
    {
        $offenders = [];
        foreach (self::sources() as $path => $code) {
            if (str_contains($code, $namespace.'\\')) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, \sprintf(
            'The registry must not name %s. It decides about the contracts, so an installation can answer them itself.',
            $namespace,
        ));
    }

    /**
     * Every other bundle of the core. The registry is what they register WITH;
     * a dependency in this direction is the arrow pointing the wrong way.
     *
     * @return \Generator<string, array{non-empty-string}>
     */
    public static function siblingNamespaces(): \Generator
    {
        foreach (['TeamBundle', 'ShellBundle', 'AreaBundle', 'AtlasBundle'] as $bundle) {
            $fqcn = 'Uhifadhi\\Bundle\\'.$bundle;
            yield $fqcn => [$fqcn];
        }
    }

    /**
     * THE REGISTRY RENDERS NOTHING. No templates directory, no controllers, no
     * routes — a module grid is a picture of the catalogue, and pictures are the
     * shell's. docs/boundaries.md says why at length; this is the part a refactor
     * cannot quietly disagree with.
     */
    public function testTheRegistryShipsNoUserInterface(): void
    {
        self::assertDirectoryDoesNotExist(self::BUNDLE.'/templates', 'Templates belong to the shell.');
        self::assertDirectoryDoesNotExist(self::BUNDLE.'/Controller', 'Controllers belong to the shell.');

        $offenders = [];
        foreach (self::sources() as $path => $code) {
            if (str_contains($code, 'Symfony\\Component\\Routing\\Attribute\\Route')
                || str_contains($code, 'AbstractController')
                || str_contains($code, '.html.twig')) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, 'The registry exposes data and services; it draws nothing.');
    }

    /**
     * ONE COMMAND IN THIS BUNDLE, AND THE CONSOLE COMPONENT IS NAMED ONLY WHERE
     * IT IS WIRED. `registry:sync` is the step of an install that only the
     * registry can do — reconcile the catalogue with the installed providers —
     * so it ships here, on a production installation, beside the tables it
     * fills. Everything else a person types belongs to devkit, a dev-only
     * package; a second file under `Command/` is that ruling being undone.
     */
    public function testTheRegistryShipsExactlyOneConsoleCommand(): void
    {
        $commands = glob(self::BUNDLE.'/Command/*.php');
        self::assertSame(
            [self::BUNDLE.'/Command/RegistrySyncCommand.php'],
            false === $commands ? [] : $commands,
            'registry:sync is the one command; a seeder belongs to devkit.',
        );

        $offenders = [];
        foreach (self::sources() as $path => $code) {
            if (str_contains($code, 'Symfony\\Component\\Console\\')
                || str_contains($code, "'console.command'")) {
                $offenders[] = $path;
            }
        }
        sort($offenders);

        self::assertSame(
            ['Command/RegistrySyncCommand.php', 'config/services.php'],
            $offenders,
            'Only the command and the file that wires it may name the console component.',
        );
    }

    /**
     * THE REGISTRY IS NOT A SECURITY PACKAGE, and its composer.json is where
     * that is true or not.
     *
     * Authenticating a request is mechanism, and mechanism about PEOPLE belongs
     * with the people: a credential and the thing that reads it live in one
     * bundle, beside the account they are a credential of. Nothing the registry
     * does needs a firewall — it holds a catalogue, a ledger and the permission
     * strings modules declared — so a security requirement appearing here is
     * the boundary moving, and it moves in a composer.json long before it moves
     * in any class.
     */
    public function testTheRegistryRequiresNoSecurityPackage(): void
    {
        $manifest = file_get_contents(self::BUNDLE.'/composer.json');
        \assert(false !== $manifest);

        /** @var array{require: array<string, string>, require-dev?: array<string, string>} $composer */
        $composer = json_decode($manifest, true, 512, \JSON_THROW_ON_ERROR);

        $security = array_filter(
            array_keys([...$composer['require'], ...$composer['require-dev'] ?? []]),
            static fn (string $package): bool => str_starts_with($package, 'symfony/security'),
        );

        self::assertSame([], array_values($security), 'The registry decides nothing about who is asking.');
    }

    /** And no security symbol reaches its shipped source either. */
    public function testTheRegistryNamesNoSecuritySymbol(): void
    {
        $offenders = [];
        foreach (self::sources() as $path => $code) {
            if (str_contains($code, 'Symfony\\Component\\Security\\')) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, 'Authenticating a request is the team bundle\'s, beside the credential it reads.');
    }

    /**
     * @return array<string, string> relative path => source
     */
    private static function sources(): array
    {
        $root = realpath(self::BUNDLE);
        \assert(false !== $root);

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen($root) + 1);
            // The suite is not shipped — .gitattributes export-ignores it — and
            // it names modules and hosts on purpose.
            if (str_starts_with($relative, 'tests/')) {
                continue;
            }
            // Symfony auto-dumps config/reference.php ("for apps only") when a
            // configured bundle boots in debug; it is gitignored, not shipped.
            if ('config/reference.php' === $relative) {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            if (false === $code) {
                continue;
            }
            $files[$relative] = $code;
        }

        ksort($files);

        return $files;
    }
}
