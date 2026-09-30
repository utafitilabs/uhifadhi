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

namespace Uhifadhi\Contracts\Tests\Deletion;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Deletion\DeletionContributorInterface;
use Uhifadhi\Contracts\Deletion\DeletionLine;
use Uhifadhi\Contracts\Deletion\DeletionSubject;

final class DeletionContractTest extends TestCase
{
    public function testASubjectCarriesWhatTheConfirmationAsksAndTheAuditKeeps(): void
    {
        $subject = new DeletionSubject(kind: 'patrol', reference: 'P-0142', title: 'Patrol P-0142 · Riverbend', summary: 'Foot patrol · S. Laizer + 2', recordUrl: '/patrols/p', afterUrl: '/patrols', register: 'patrols');

        self::assertSame('P-0142', $subject->reference);
        self::assertSame('patrol', $subject->kind);
    }

    public function testASubjectWithoutAReferenceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('what the Super Admin types');

        new DeletionSubject(kind: 'patrol', reference: '  ', title: 'Patrol', summary: '', recordUrl: '/p', afterUrl: '/', register: 'patrols');
    }

    public function testALineCountsWhatItNamesAndMayListItByName(): void
    {
        $line = new DeletionLine('observations', 3, items: [['1 · 06:48 · wildlife', 'Elephant herd · 2 photos']]);

        self::assertSame(3, $line->count);
        self::assertSame([['1 · 06:48 · wildlife', 'Elephant herd · 2 photos']], $line->items);
        self::assertSame('3 observations', $line->phrase());
    }

    public function testALineOfOneReadsInTheSingularItWasGiven(): void
    {
        self::assertSame('1 patrol', new DeletionLine('patrols', 1, singular: 'patrol')->phrase());
    }

    public function testANegativeCountIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DeletionLine('photos', -1);
    }

    public function testTheTagIsTheOneTheCoreCollects(): void
    {
        self::assertSame('uhifadhi.deletion_contributor', DeletionContributorInterface::TAG);
    }
}
