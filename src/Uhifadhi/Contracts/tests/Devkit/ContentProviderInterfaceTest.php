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

namespace Uhifadhi\Contracts\Tests\Devkit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;

/**
 * The seed-content contract asks four questions and offers one verb: what is
 * this content called (the key), what does a human call it (the label), what
 * does it seed (the description), what must run before it (dependsOn) — and then
 * load(), which does the seeding. Two things are worth a test: that the
 * published surface is exactly those and no wider, and that a module can satisfy
 * the whole of it with an ordinary service that owns its own dependencies.
 */
final class ContentProviderInterfaceTest extends TestCase
{
    /**
     * THE SURFACE, TYPED OUT BY HAND — the same discipline the entity contracts
     * use: a list derived from the interface would agree with whatever the
     * interface happens to say; written out separately, the two disagree loudly
     * the day somebody widens a contract other people implement.
     *
     * @return list<array{string, string}>
     */
    public static function surface(): array
    {
        return [
            ['key', 'string'],
            ['label', 'string'],
            ['description', 'string'],
            ['dependsOn', 'array'],
            ['load', 'void'],
        ];
    }

    public function testTheContractPublishesExactlyTheMeasuredSurface(): void
    {
        $reflection = new \ReflectionClass(ContentProviderInterface::class);

        self::assertTrue($reflection->isInterface(), 'The content contract is an interface, not a class.');

        $declared = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(),
        );
        sort($declared);

        $expected = array_column(self::surface(), 0);
        sort($expected);

        self::assertSame($expected, $declared);
    }

    /**
     * @param non-empty-string $method
     */
    #[DataProvider('surface')]
    public function testEveryMethodTakesNoArgumentsAndReturnsItsDeclaredType(string $method, string $returnType): void
    {
        $reflection = new \ReflectionMethod(ContentProviderInterface::class, $method);

        self::assertSame([], $reflection->getParameters(), \sprintf('%s() takes no arguments: a content provider injects what it needs through its constructor.', $method));
        self::assertSame($returnType, (string) $reflection->getReturnType());
    }

    /**
     * FRAMEWORK-FREE, AND THE TEST SAYS SO. The whole point of this package is
     * that depending on it costs nothing, so the content contract may not reach
     * for Doctrine, for symfony/console, or for anything else — devkit, which is
     * require-dev, is the only place a framework may enter.
     */
    public function testTheContractImportsNothing(): void
    {
        $file = (new \ReflectionClass(ContentProviderInterface::class))->getFileName();
        self::assertIsString($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        self::assertSame(0, preg_match('/^use /m', $source), 'The content contract imports nothing: no ORM, no console, no framework.');
    }

    /**
     * A module satisfies the contract with an ordinary service: it names itself,
     * declares what it needs seeded first, and does the work in load() using the
     * dependencies it was constructed with. devkit topologically sorts by key
     * and dependsOn, then calls load() — dev-only — on each in turn.
     */
    public function testAnImplementationIsAnOrdinaryServiceThatOwnsItsDependencies(): void
    {
        /** @var \ArrayObject<int, string> $seeded */
        $seeded = new \ArrayObject();

        $areas = new class($seeded) implements ContentProviderInterface {
            /** @param \ArrayObject<int, string> $sink */
            public function __construct(private \ArrayObject $sink)
            {
            }

            public function key(): string
            {
                return 'area';
            }

            public function label(): string
            {
                return 'Areas';
            }

            public function description(): string
            {
                return 'A handful of seed conservation areas to hang everything else on.';
            }

            public function dependsOn(): array
            {
                return [];
            }

            public function load(): void
            {
                $this->sink->append('area');
            }
        };

        $incidents = new class($seeded) implements ContentProviderInterface {
            /** @param \ArrayObject<int, string> $sink */
            public function __construct(private \ArrayObject $sink)
            {
            }

            public function key(): string
            {
                return 'incident';
            }

            public function label(): string
            {
                return 'Incidents';
            }

            public function description(): string
            {
                return 'Sample incidents raised inside the seed areas.';
            }

            public function dependsOn(): array
            {
                return ['area'];
            }

            public function load(): void
            {
                $this->sink->append('incident');
            }
        };

        self::assertSame('incident', $incidents->key());
        self::assertSame('Incidents', $incidents->label());
        self::assertSame(['area'], $incidents->dependsOn(), 'A provider names the keys that must be seeded before it.');
        self::assertSame([], $areas->dependsOn());

        // devkit would resolve the order from the dependsOn edges; here it is
        // spelled out to prove load() does the work through the injected sink.
        $areas->load();
        $incidents->load();
        self::assertSame(['area', 'incident'], $seeded->getArrayCopy(), 'load() seeds through the dependencies the service was built with.');
    }
}
