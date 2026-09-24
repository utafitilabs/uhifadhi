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

namespace Uhifadhi\Contracts\Settings;

/**
 * WHO THIS INSTALLATION BELONGS TO — the name it is known by, its mark, and
 * where in the world it is.
 *
 * EVERY FIELD STATES ITS CONSEQUENCE ON THE SCREEN, which is why they are
 * separate fields rather than a bag: the short name is what is drawn where
 * the full one will not fit, the zone is what every instant on every screen
 * is read in, the country is the map's default extent. A field with no stated
 * consequence is a field nobody can decide the value of.
 *
 * A NULL IS "NOT SET", AND THE SCREEN SAYS SO. The logo falls back to the
 * wordmark and the screen prints the fallback rather than an empty cell —
 * somebody deciding whether to upload one needs to know what happens if they
 * do not.
 */
final readonly class OrganizationIdentity
{
    /**
     * @param string      $name      the full name, drawn in the header and on every export
     * @param string|null $shortName used where the full name will not fit
     * @param string|null $logo      a URL to the mark, or null for the wordmark
     * @param string|null $timeZone  an IANA zone name, e.g. "Africa/Dar_es_Salaam"
     * @param string|null $country   where it is, in the reader's words
     */
    public function __construct(
        public string $name,
        public ?string $shortName = null,
        public ?string $logo = null,
        public ?string $timeZone = null,
        public ?string $country = null,
    ) {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('An organization is known by its name: it cannot be empty.');
        }
    }

    /**
     * The zone's offset as it is drawn beside it — "UTC+3" — or null where no
     * zone is set, and null again where the name is one PHP does not know,
     * because a wrong offset is worse than none.
     */
    public function utcOffset(\DateTimeImmutable $at): ?string
    {
        if (null === $this->timeZone) {
            return null;
        }

        try {
            $zone = new \DateTimeZone($this->timeZone);
        } catch (\Exception) {
            return null;
        }

        $minutes = intdiv($at->setTimezone($zone)->getOffset(), 60);
        $sign = $minutes < 0 ? '-' : '+';
        $minutes = abs($minutes);

        return \sprintf(
            0 === $minutes % 60 ? 'UTC%s%d' : 'UTC%s%d:%02d',
            $sign,
            intdiv($minutes, 60),
            $minutes % 60,
        );
    }
}
