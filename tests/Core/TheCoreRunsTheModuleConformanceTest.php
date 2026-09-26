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
use Uhifadhi\Bundle\AreaBundle\Access\AreaConcerns;
use Uhifadhi\Bundle\AreaBundle\AreaBundle;
use Uhifadhi\Bundle\TeamBundle\Test\AccessConformanceTestCase;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;

/**
 * THE CORE RUNS THE CONFORMANCE IT ASKS MODULES TO RUN.
 *
 * {@see AccessConformanceTestCase} is the base a module extends in its own
 * CI, and a base nobody has watched pass is a base that might be asserting
 * nothing. So the core's own three declarations go through it, exactly as a
 * module's would — which also means a change that makes the base wrong is
 * caught here rather than in somebody else's repository.
 *
 * THE CORE'S CONCERNS BELONG TO NO MODULE, deliberately: the ground, the
 * directory and the catalogue are the installation's, not any one
 * department's, and that is what makes the third question a check asks — does
 * the placement cover the department — not arise for them.
 *
 * ONE CLASS PER FILE, AND THAT IS NOT TIDINESS. PHPUnit collects the one
 * class whose name matches the file; the team's and the catalogue's
 * conformance sat in this file with the core's and never ran at all. They
 * have files of their own now — {@see TheTeamRunsTheModuleConformanceTest},
 * {@see TheRegistryRunsTheModuleConformanceTest}.
 */
#[CoversNothing]
final class TheCoreRunsTheModuleConformanceTest extends AccessConformanceTestCase
{
    protected static function source(): ConcernSourceInterface
    {
        return new AreaConcerns();
    }

    protected static function bundlePath(): string
    {
        return \dirname((string) (new \ReflectionClass(AreaBundle::class))->getFileName());
    }

    /** A person's live position is a fact about that person. */
    protected static function sensitiveConcerns(): array
    {
        return ['locations'];
    }
}
