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

namespace Uhifadhi\Bundle\AtlasBundle\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerShape;
use Uhifadhi\Bundle\AtlasBundle\Model\LiveMarks;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\LivePosition;
use Uhifadhi\Contracts\Area\LivePresence;
use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * WHERE PEOPLE ARE, ON THE PLATE.
 *
 * The one mark the accent is reserved for, and the two things a reader has to
 * be told about it: which of these positions nobody should be believing any
 * more, and how many people are not on the ground at all.
 */
final class LiveMarksTest extends TestCase
{
    private const string NOW = '2026-09-20 09:00:00';

    /**
     * A layer's features cross into this test as `mixed`, because a layer is
     * handed whatever GeoJSON a contributor built. Every step down into it is
     * a claim, made loudly here rather than hidden behind a nullsafe.
     *
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    /**
     * The features of a layer, each one an array.
     *
     * @return list<array<array-key, mixed>>
     */
    private static function features(\Uhifadhi\Bundle\AtlasBundle\Model\GeoJsonLayer $layer): array
    {
        return array_values(array_map(self::arr(...), self::arr(self::arr($layer->features)['features'] ?? null)));
    }

    private static function position(string $name, int $minutesAgo, float $lat = -3.2, float $lon = -29.5): LivePosition
    {
        return new LivePosition(
            personUuid: strtolower(str_replace(' ', '-', $name)),
            personName: $name,
            clientRef: 'w-1',
            state: DayState::AtPostVerified,
            latitude: $lat,
            longitude: $lon,
            recordedAt: new \DateTimeImmutable(self::NOW.' -'.$minutesAgo.' minutes'),
        );
    }

    private static function presence(LivePosition ...$positions): LivePresence
    {
        // Fifteen-minute pings, so two intervals is thirty minutes.
        return new LivePresence(
            positions: array_values($positions),
            pingIntervalMinutes: 15,
            asOf: new \DateTimeImmutable(self::NOW),
        );
    }

    /** ONE MARK PER POSITION, and the geometry is the position. */
    public function testEveryPositionInTheReadIsOneFeature(): void
    {
        $layer = LiveMarks::layer(self::presence(
            self::position('J. Mollel', 4, -3.20, -29.50),
            self::position('T. Ndosi', 11, -3.21, -29.51),
        ));

        self::assertSame(LiveMarks::LAYER_ID, $layer->id);
        self::assertSame(LayerShape::Live, $layer->shape);
        self::assertSame(PlatePalette::ACCENT, $layer->swatch);
        self::assertCount(2, self::features($layer));
        self::assertSame(
            [-29.50, -3.20],
            self::arr(self::features($layer)[0]['geometry'])['coordinates'],
            'GeoJSON is longitude first, and a marker one axis out is a marker in the wrong country.',
        );
    }

    /**
     * STALE IS THE CONTRACT'S READING, NOT THE PLATE'S. Two ping intervals is
     * the line, and it is drawn once — in {@see LivePresence} — so a plate, a
     * list and a phone cannot disagree about who is current.
     */
    public function testAPositionOlderThanTwoPingIntervalsIsMarkedStale(): void
    {
        $layer = LiveMarks::layer(self::presence(
            self::position('J. Mollel', 4),
            self::position('K. Parmuat', 31),
        ));

        $stale = array_map(
            static fn (array $feature): mixed => self::arr($feature['properties'])['stale'],
            self::features($layer),
        );

        self::assertSame([false, true], $stale);

        // And the layer's own count is the LIVE ones: a legend row saying
        // "live position · 2" while one of them is an hour old is a lie.
        self::assertSame(1, $layer->count);
    }

    /** The mark carries who it is and how old the fix is, in two letters and a duration. */
    public function testTheMarkCarriesTheInitialsAndTheAgeOfTheFix(): void
    {
        $layer = LiveMarks::layer(self::presence(
            self::position('J. Mollel', 4),
            self::position('Naishorua Lekishon', 81),
            self::position('Kessy', 0),
        ));

        $properties = array_map(
            static fn (array $feature): array => [
                self::arr($feature['properties'])['initials'],
                self::arr($feature['properties'])['age'],
            ],
            self::features($layer),
        );

        self::assertSame([['JM', '4 min'], ['NL', '1 h 21'], ['K', '0 min']], $properties);
    }

    /**
     * SOMEBODY WITH NO FIX IS NOT ON THE GROUND — the contract carries the
     * positions it has and nothing else, so an empty read draws nothing at
     * all rather than a plate full of guesses.
     */
    public function testAReadWithNoPositionsDrawsNothing(): void
    {
        $layer = LiveMarks::layer(self::presence());

        self::assertSame([], self::features($layer));
        self::assertSame(0, $layer->count);
    }

    /**
     * EVERY LAYER SHIPS A LEGEND, and this one's says more than the layer can:
     * the state it draws dimmed, and the state it draws NOWHERE.
     */
    public function testTheKeyStatesTheStaleAndTheAbsentBesideTheLayersOwnRow(): void
    {
        $presence = self::presence(
            self::position('J. Mollel', 4),
            self::position('K. Parmuat', 31),
        );

        $key = LiveMarks::key($presence, withoutPosition: 3);

        self::assertCount(2, $key);
        self::assertSame(LayerShape::LiveStale, $key[0]->shape);
        self::assertSame(1, $key[0]->count);
        self::assertSame(LayerShape::LiveAbsent, $key[1]->shape);
        self::assertSame(3, $key[1]->count);

        // Nothing on the ground shows the absent, so its row is not drawn on.
        self::assertFalse($key[1]->visible);

        // One heading, so the three states read as one mark in three states.
        self::assertSame(
            [LiveMarks::GROUP, LiveMarks::GROUP],
            [$key[0]->group, $key[1]->group],
        );
        self::assertSame(LiveMarks::GROUP, LiveMarks::layer($presence)->group);
    }

    /**
     * BOTH KEY ROWS ARE STATED WHATEVER THEIR COUNTS. A key says what a mark
     * MEANS, and "none of them are stale" is an answer a reader wants as much
     * as "one of them is".
     */
    public function testTheKeyIsStatedEvenWhenEveryPositionIsCurrent(): void
    {
        $key = LiveMarks::key(self::presence(self::position('J. Mollel', 4)));

        self::assertCount(2, $key);
        self::assertSame([0, 0], [$key[0]->count, $key[1]->count]);
    }

    /** The dot's three states are one primitive wearing a modifier. */
    public function testTheThreeStatesAreOneMarkWithAModifier(): void
    {
        self::assertSame('livedot', LayerShape::Live->liveDot());
        self::assertSame('livedot stale', LayerShape::LiveStale->liveDot());
        self::assertSame('livedot none', LayerShape::LiveAbsent->liveDot());
        self::assertNull(LayerShape::Fill->liveDot());
        self::assertNull(LayerShape::Point->liveDot());
    }

    /**
     * ONE POSITION AS ONE FRAME — the feature the layer already draws, so a
     * mark that arrives over the wire is the mark that arrived with the page.
     * It carries what a plate needs to keep the age and the staleness moving
     * on its own clock: the instant of the fix and the silence that makes it
     * stale, in seconds, from the position's own interval where it states one.
     */
    public function testAFrameIsTheLayersOwnFeatureWithWhatKeepsItsClockRunning(): void
    {
        $presence = self::presence(self::position('J. Mollel', 4));

        $frame = LiveMarks::frame($presence, $presence->positions[0]);

        self::assertSame('Feature', $frame['type']);
        self::assertSame('j.-mollel', $frame['id']);
        self::assertSame(self::features(LiveMarks::layer($presence))[0], $frame, 'the same feature the layer draws');
        $properties = self::arr($frame['properties']);
        self::assertSame(['name', 'initials', 'age', 'stale', 'at', 'staleAfterSeconds'], array_keys($properties));
        self::assertSame(new \DateTimeImmutable(self::NOW.' -4 minutes')->format(\DateTimeInterface::ATOM), $properties['at']);
        self::assertSame(15 * 60 * LivePresence::STALE_AFTER_INTERVALS, $properties['staleAfterSeconds']);
    }

    /** A position that states its own interval is judged by it, on the wire as on the plate. */
    public function testAFrameKeepsItsOwnAreasInterval(): void
    {
        $own = new LivePosition(
            personUuid: 'p-5',
            personName: 'K. Parmuat',
            clientRef: 'w-1',
            state: DayState::AtPostVerified,
            latitude: -3.2,
            longitude: -29.5,
            recordedAt: new \DateTimeImmutable(self::NOW.' -4 minutes'),
            pingIntervalMinutes: 5,
        );
        $presence = self::presence($own);

        self::assertSame(600, self::arr(LiveMarks::frame($presence, $own)['properties'])['staleAfterSeconds']);
    }

    /**
     * SOMEBODY WHO LEFT THE GROUND is a frame with no point: the same key,
     * nothing to draw, and one word saying why.
     */
    public function testAGoneFrameNamesThePersonAndDrawsNothing(): void
    {
        self::assertSame(
            ['type' => 'Feature', 'id' => 'p-5', 'geometry' => null, 'properties' => ['gone' => true]],
            LiveMarks::gone('p-5'),
        );
    }
}
