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

namespace Uhifadhi\Bundle\ShellBundle\Service;

use Symfony\Component\HttpFoundation\Request;
use Uhifadhi\Bundle\ShellBundle\Model\InstalledPackage;

/**
 * WHAT IS INSTALLED, AS A REGISTER — the rows the bar leaves, the counts its
 * one chip carries and the page the Shell's foot draws.
 *
 * THE CHIP IS WHAT A PACKAGE WAS INSTALLED FROM: a release, or a branch.
 * That is the one fact about a package the installation can read about
 * itself — Composer says so in the version it reports — where "current" or
 * "behind" would need a release feed nothing here subscribes to. Its counts
 * are over every package, so an option never reads 0 because somebody is
 * searching. The search reads the name and the package's line.
 */
final readonly class PackageRegisterService
{
    public const string FROM = 'from';

    public const string SEARCH = 'q';

    public const string RELEASE = 'release';

    public const string BRANCH = 'branch';

    /** The sources in the order the chip lists them, each with its label. */
    public const array SOURCES = [self::RELEASE => 'a release', self::BRANCH => 'a branch'];

    public function __construct(private TablePageService $pages)
    {
    }

    /**
     * Composer reports a branch as `dev-<name>` or `<line>.x-dev`, and a
     * package it cannot place as `dev`; everything else is a release.
     *
     * @see https://getcomposer.org/doc/articles/versions.md#branches
     */
    public static function sourceOf(InstalledPackage $package): string
    {
        $version = strtolower($package->version);

        return 'dev' === $version || str_starts_with($version, 'dev-') || str_ends_with($version, '-dev') ? self::BRANCH : self::RELEASE;
    }

    /**
     * @param list<InstalledPackage> $packages every package installed here
     *
     * @return array{
     *     rows: list<InstalledPackage>,
     *     filter: array{from: ?string, q: ?string},
     *     counts: array<string, int>,
     *     foot: array{count: int, noun: string, one: string, perPage: string, page: int, pages: int, links: array{sizes: array{20: string, 50: string, 100: string, all: string}, previous: ?string, next: ?string}}
     * }
     */
    public function read(Request $request, array $packages): array
    {
        $from = self::text($request, self::FROM);
        $from = null !== $from && isset(self::SOURCES[$from]) ? $from : null;
        $search = self::text($request, self::SEARCH);

        $counts = ['all' => \count($packages)] + array_fill_keys(array_keys(self::SOURCES), 0);
        foreach ($packages as $package) {
            ++$counts[self::sourceOf($package)];
        }

        $left = array_values(array_filter($packages, static fn (InstalledPackage $package): bool => (null === $from || $from === self::sourceOf($package))
            && (null === $search || false !== mb_stripos($package->name.' '.$package->line(), $search))));
        $table = $this->pages->read($request, \count($left));

        return [
            'rows' => $table->slice($left),
            'filter' => [self::FROM => $from, self::SEARCH => $search],
            'counts' => $counts,
            'foot' => [
                'count' => $table->total,
                'noun' => 'packages',
                'one' => 'package',
                'perPage' => $table->size,
                'page' => $table->page,
                'pages' => $table->pages,
                'links' => $this->pages->links($request, $table),
            ],
        ];
    }

    private static function text(Request $request, string $key): ?string
    {
        $value = $request->query->all()[$key] ?? null;

        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
