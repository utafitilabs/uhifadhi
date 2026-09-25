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

namespace Uhifadhi\Bundle\AtlasBundle\Model;

use Uhifadhi\Contracts\Area\LivePosition;
use Uhifadhi\Contracts\Area\LivePresence;
use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * WHERE PEOPLE ARE, ON THE PLATE — the layer and the key that go with it.
 *
 * The atlas draws every module's visuals, and this is the one the roster,
 * patrols and anything else with a handset all want: the positions a
 * {@see LivePresence} carries, as the live dot in its two drawn states.
 *
 * THE PLATE ANSWERS ONE QUESTION. Is this where the person IS, or where they
 * WERE? A position younger than two ping intervals is live, an older one is
 * stale, and that is the whole vocabulary. What the person is DOING — at post
 * verified, special assignment, working elsewhere — is a row's business
 * wherever the rows are; a marker that carried it would be a second legend
 * nobody asked for, and would spend the accent on something that is not
 * liveness.
 *
 * SOMEBODY WITH NO FIX IS NOT ON THE GROUND. The contract only carries the
 * positions it has, so there is nothing here to filter: absence is stated in
 * the key, with its count, and never guessed at on the imagery.
 *
 * EVERY LAYER SHIPS A LEGEND (the map-legend contract), so the two come from
 * one call — {@see AtlasMap::livePositions()} — rather than a caller being
 * trusted to remember the key for a mark it did not draw.
 */
final class LiveMarks
{
    public const string LAYER_ID = 'atlas.live';

    /** The heading the mark and its key sit under, in the design's words. */
    public const string GROUP = 'On the plate';

    /**
     * The layer: one point per position the contract carries, each marked
     * stale or not by the contract's own reading of its age.
     */
    public static function layer(LivePresence $presence, string $label = 'Live position'): GeoJsonLayer
    {
        $features = [];
        $live = 0;
        foreach ($presence->positions as $position) {
            $stale = $presence->isStale($position);
            $live += $stale ? 0 : 1;
            $features[] = self::feature($position, $stale, $presence);
        }

        return new GeoJsonLayer(
            id: self::LAYER_ID,
            label: $label,
            features: ['type' => 'FeatureCollection', 'features' => $features],
            swatch: PlatePalette::ACCENT,
            shape: LayerShape::Live,
            count: $live,
            group: self::GROUP,
        );
    }

    /**
     * The key beside it: the state the layer draws dimmed, and the one it
     * draws nowhere at all.
     *
     * Both rows are stated whatever their counts, because a key says what a
     * mark MEANS and "none of them are stale" is an answer a reader wants as
     * much as "one of them is". The absent row is drawn off, because there is
     * nothing on the ground it could turn off.
     *
     * @param int $withoutPosition how many people the caller is holding that the
     *                             contract had no fix for — it knows the roll, and
     *                             the presence read only knows who answered
     *
     * @return list<LegendItem>
     */
    public static function key(LivePresence $presence, int $withoutPosition = 0): array
    {
        return [
            new LegendItem(
                label: 'Stale · older than two intervals',
                swatch: PlatePalette::DIM,
                shape: LayerShape::LiveStale,
                group: self::GROUP,
                count: $presence->staleCount(),
            ),
            new LegendItem(
                label: 'No position',
                swatch: PlatePalette::FAIL,
                shape: LayerShape::LiveAbsent,
                group: self::GROUP,
                count: $withoutPosition,
                visible: false,
            ),
        ];
    }

    /**
     * ONE POSITION AS ONE FRAME ON THE WIRE — and it is the very feature the
     * layer draws at page load, so a mark that arrives live is the mark that
     * arrived with the page. Whoever publishes a person's movement builds the
     * frame here rather than restating the vocabulary.
     *
     * The frame carries what a plate needs to keep the age and the staleness
     * moving on its own clock once the server is out of the picture: the
     * instant of the fix, and the silence that makes it stale — the position's
     * own interval where it states one, the set's otherwise, two of them, in
     * seconds ({@see LivePresence::isStale()} drawn once).
     *
     * @return array<string, mixed>
     */
    public static function frame(LivePresence $presence, LivePosition $position): array
    {
        return self::feature($position, $presence->isStale($position), $presence);
    }

    /**
     * SOMEBODY WHO LEFT THE GROUND — checked out, or their watch ended — as a
     * frame with no point: the same key, nothing to draw, one word saying
     * why. The plate takes the mark off.
     *
     * @return array{type: string, id: string, geometry: null, properties: array{gone: true}}
     */
    public static function gone(string $personUuid): array
    {
        return ['type' => 'Feature', 'id' => $personUuid, 'geometry' => null, 'properties' => ['gone' => true]];
    }

    /**
     * One position as a feature the plate can draw: where it is, who it is,
     * how old the fix is in words the marker prints beside itself, and what
     * the plate needs to keep that reading current on its own.
     *
     * @return array<string, mixed>
     */
    private static function feature(LivePosition $position, bool $stale, LivePresence $presence): array
    {
        $interval = $position->pingIntervalMinutes ?? $presence->pingIntervalMinutes;

        return [
            'type' => 'Feature',
            'id' => $position->personUuid,
            'geometry' => ['type' => 'Point', 'coordinates' => [$position->longitude, $position->latitude]],
            'properties' => [
                'name' => $position->personName,
                'initials' => self::initials($position->personName),
                'age' => self::age($position->ageSeconds($presence->asOf)),
                'stale' => $stale,
                'at' => $position->recordedAt->format(\DateTimeInterface::ATOM),
                'staleAfterSeconds' => $interval * 60 * LivePresence::STALE_AFTER_INTERVALS,
            ],
        ];
    }

    /**
     * A NAME IN TWO LETTERS, and never more: the core is eight pixels across
     * and a third letter makes it a blob. One-word names keep one letter
     * rather than borrowing the second from inside the word, which reads as a
     * different person's initials.
     */
    private static function initials(string $name): string
    {
        $letters = '';
        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $word) {
            if ('' === $word) {
                continue;
            }
            $letters .= mb_strtoupper(mb_substr($word, 0, 1));
            if (2 === mb_strlen($letters)) {
                break;
            }
        }

        return $letters;
    }

    /**
     * HOW OLD THE FIX IS, in the shape the design prints: "4 min" up to an
     * hour, then "4 h 21".
     *
     * A DURATION IS NOT AN INSTANT, which is why this is formatted here and
     * not handed to the shell's `<time>` element: the marker is saying how
     * long ago the phone spoke, and that number is the same in every timezone.
     */
    private static function age(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);
        if ($minutes < 60) {
            return $minutes.' min';
        }

        return intdiv($minutes, 60).' h '.str_pad((string) ($minutes % 60), 2, '0', \STR_PAD_LEFT);
    }
}
