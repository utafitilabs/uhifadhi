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

namespace Uhifadhi\Test;

use Symfony\Component\Routing\Route;

/**
 * WHAT A ROUTE SAYS IT ENFORCES, read off the code that enforces it.
 *
 * Two of the four proofs need the same answer — the router walk and the
 * route-by-position matrix — and a second reading of it would eventually
 * disagree with the first, which is the one failure a conformance suite must
 * not have.
 *
 * IT READS THE SOURCE, NOT A REGISTRY. Symfony records `#[IsGranted]` as a
 * controller attribute rather than as route metadata, and a gate written in
 * code (`denyAccessUnlessGranted`) is just as much a gate — so the honest
 * place to look is the method itself, together with the attribute block
 * above its signature and nothing further, so a pair mentioned in a
 * neighbouring method is never credited to this route.
 */
final class GateReader
{
    /**
     * The pairs one route enforces.
     *
     * @return list<string>
     */
    public static function pairsOn(Route $route): array
    {
        $controller = $route->getDefault('_controller');
        if (!\is_string($controller)) {
            return [];
        }

        $source = self::methodSource($controller);
        if (null === $source) {
            return [];
        }

        $class = explode('::', $controller, 2)[0];
        $imports = self::importsIn(self::fileOf($class));

        preg_match_all(
            "/(?:IsGranted|isGranted|denyAccessUnlessGranted)\\(\\s*(?:'(?<literal>[a-z0-9]+(?:-[a-z0-9]+)*\\.[a-z]+)'|(?<constant>(?:self|static|[A-Za-z_\\\\]+)::[A-Z_][A-Z0-9_]*))/",
            $source,
            $matches,
            \PREG_SET_ORDER,
        );

        $pairs = [];
        foreach ($matches as $match) {
            if ('' !== ($match['literal'] ?? '')) {
                $pairs[] = $match['literal'];
                continue;
            }

            // A GATE MAY NAME ITS PAIR THROUGH A CONSTANT, and several do —
            // one place to change it, and the test that names it stays in
            // step. Resolving it here is the difference between reading what
            // the gate enforces and reading how it was spelled.
            $resolved = self::constant($match['constant'] ?? '', $class, $imports);
            if (null !== $resolved) {
                $pairs[] = $resolved;
            }
        }

        $pairs = array_values(array_unique($pairs));
        sort($pairs);

        return $pairs;
    }

    /**
     * EVERY GATE IN ONE SHIPPED FILE, wherever it is written — a controller,
     * an API state provider, a navigation source. A pair spelled through a
     * constant is resolved, because a gate that names its pair once and
     * reuses it is doing the right thing and must not read as doing nothing.
     *
     * @return list<string>
     */
    public static function pairsInFile(string $path): array
    {
        $source = (string) file_get_contents($path);
        $class = self::classIn($source);
        $imports = self::importsIn($path);

        preg_match_all(
            "/(?:IsGranted|isGranted|denyAccessUnlessGranted)\\(\\s*(?:'(?<literal>[a-z0-9]+(?:-[a-z0-9]+)*\\.[a-z]+)'|(?<constant>(?:self|static|[A-Za-z_\\\\]+)::[A-Z_][A-Z0-9_]*))/",
            $source,
            $matches,
            \PREG_SET_ORDER,
        );

        $pairs = [];
        foreach ($matches as $match) {
            if ('' !== ($match['literal'] ?? '')) {
                $pairs[] = $match['literal'];
                continue;
            }

            $resolved = null === $class ? null : self::constant($match['constant'] ?? '', $class, $imports);
            if (null !== $resolved) {
                $pairs[] = $resolved;
            }
        }

        $pairs = array_values(array_unique($pairs));
        sort($pairs);

        return $pairs;
    }

    /**
     * The file a class was declared in, so its imports can be read.
     */
    private static function fileOf(string $class): ?string
    {
        if (!class_exists($class)) {
            return null;
        }

        $file = new \ReflectionClass($class)->getFileName();

        return false === $file ? null : $file;
    }

    /**
     * The `use` statements of one file, short name to fully qualified.
     *
     * @return array<string, string>
     */
    private static function importsIn(?string $path): array
    {
        if (null === $path || !is_file($path)) {
            return [];
        }

        preg_match_all('/^use\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?:\s+as\s+(\w+))?;/m', (string) file_get_contents($path), $matches, \PREG_SET_ORDER);

        $imports = [];
        foreach ($matches as $match) {
            $fqcn = $match[1];
            $alias = '' !== ($match[2] ?? '') ? $match[2] : substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
            $imports[$alias] = $fqcn;
        }

        return $imports;
    }

    /** The fully qualified name of the one class a shipped file declares. */
    private static function classIn(string $source): ?string
    {
        if (1 !== preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)) {
            return null;
        }

        if (1 !== preg_match('/^(?:final\s+|readonly\s+|abstract\s+)*class\s+(\w+)/m', $source, $name)) {
            return null;
        }

        return trim($namespace[1]).'\\'.$name[1];
    }

    /**
     * The value behind `self::PAIR`, `Imported::PAIR` or a fully qualified
     * `Some\Class::PAIR`, where it is a pair.
     *
     * A GATE USUALLY NAMES AN IMPORTED SHORT CLASS, because that is how PHP
     * is written — so the file's `use` statements have to be read, or a
     * perfectly good gate resolves to nothing and reads as no gate at all.
     *
     * @param array<string, string> $imports short name to fully qualified
     */
    private static function constant(string $expression, string $context, array $imports = []): ?string
    {
        if ('' === $expression) {
            return null;
        }

        [$owner, $name] = explode('::', $expression, 2);

        if (\in_array($owner, ['self', 'static'], true)) {
            $owner = $context;
        } else {
            $owner = ltrim($owner, '\\');
            $owner = $imports[$owner] ?? $owner;

            // A neighbour in the same namespace needs no import.
            if (!class_exists($owner) && !str_contains($owner, '\\')) {
                $namespace = substr($context, 0, (int) strrpos($context, '\\'));
                $owner = $namespace.'\\'.$owner;
            }
        }

        if (!class_exists($owner)) {
            return null;
        }

        // Reflection rather than `constant()`: a gate may name its pair
        // through a PRIVATE constant — one place to change it, invisible from
        // outside — and `defined()` cannot see one, which would make a
        // perfectly good gate read as no gate at all.
        try {
            $value = new \ReflectionClassConstant($owner, $name)->getValue();
        } catch (\ReflectionException) {
            return null;
        }

        return \is_string($value) && 1 === preg_match('/^[a-z0-9]+(-[a-z0-9]+)*\\.[a-z]+$/', $value) ? $value : null;
    }

    /**
     * The method's own lines, plus the attribute block immediately above its
     * signature.
     */
    private static function methodSource(string $controller): ?string
    {
        if (!str_contains($controller, '::')) {
            return null;
        }

        [$class, $method] = explode('::', $controller, 2);
        if (!class_exists($class) || !method_exists($class, $method)) {
            return null;
        }

        $reflection = new \ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();
        if (false === $file || false === $start || false === $end) {
            return null;
        }

        $lines = file($file);
        if (false === $lines) {
            return null;
        }

        // Walk back over the attributes and the docblock above the signature,
        // stopping at the blank line or closing brace that separates this
        // member from the one before it.
        $from = $start - 1;
        while ($from > 0) {
            $above = rtrim($lines[$from - 1]);
            if ('' === trim($above) || str_ends_with($above, '}')) {
                break;
            }
            --$from;
        }

        return implode('', \array_slice($lines, $from, $end - $from));
    }
}
