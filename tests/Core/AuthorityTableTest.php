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
use Uhifadhi\Core\Tests\Application\Kernel;
use Uhifadhi\Core\Tests\Core\Authority\CoreProbes;
use Uhifadhi\Testing\Authority\World;
use Uhifadhi\Testing\AuthorityTableTestCase;

/**
 * THE CORE'S AUTHORITY TABLE: every route the core mounts, through the base
 * a module extends.
 *
 *     UHIFADHI_RECORD_AUTHORITY_TABLE=1 vendor/bin/phpunit tests/Core/AuthorityTableTest.php
 */
#[CoversNothing]
final class AuthorityTableTest extends AuthorityTableTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected static function tableFile(): string
    {
        return __DIR__.'/authority-table.md';
    }

    protected static function scope(): string
    {
        return 'the core';
    }

    protected static function generator(): string
    {
        return 'tests/Core/AuthorityTableTest.php';
    }

    protected function probes(World $world): array
    {
        return CoreProbes::all($world);
    }

    protected function pending(): array
    {
        return CoreProbes::PENDING;
    }

    protected function shadowed(): array
    {
        return CoreProbes::SHADOWED;
    }

    protected function notSavableHere(): array
    {
        return CoreProbes::NOT_SAVABLE_HERE;
    }

    /**
     * ON DUTY NOW, with a fresh ping each, so the live sheet has a mark to
     * answer about: the colleague at Kilimani and the person out of reach at
     * Tambarare.
     */
    protected function arrange(World $world): void
    {
        $this->onDuty($world->member, $world->kilimani, $world->station);
        $this->onDuty($world->outOfReach, $world->tambarare, null);
    }
}
