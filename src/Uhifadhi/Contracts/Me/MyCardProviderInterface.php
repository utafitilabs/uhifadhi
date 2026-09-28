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

namespace Uhifadhi\Contracts\Me;

/**
 * WHAT A PACKAGE SHOWS A PERSON ABOUT THEMSELVES on their own dashboard —
 * the page `/` draws for somebody who cannot see the organization's (ruled
 * 28 Sep 2026, open item #19). Each package answers only with its own
 * records about this one person: the patrol module their patrols and the
 * distance they covered, the roster module their roster, the area their
 * station and check-ins. The frame never names a package.
 *
 * It is the same shape as the person record's cells
 * ({@see \Uhifadhi\Contracts\People\PersonRecordCellProviderInterface}):
 * the provider renders its own markup, so the frame needs none of its
 * templates or its vocabulary.
 */
interface MyCardProviderInterface
{
    public const string TAG = 'uhifadhi.me.cards';

    /**
     * The cards for the signed-in person, or an empty list.
     *
     * @return list<MyCard>
     */
    public function cardsFor(string $personUuid, \DateTimeImmutable $now): array;
}
