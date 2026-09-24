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

namespace Uhifadhi\Contracts\Performance;

/**
 * WHOSE PERFORMANCE IS BEING READ — the whole organization, or one area.
 *
 * A TOPIC IS ASKED THE SAME QUESTION EITHER WAY, and answers with the rows
 * its scope holds: the organization's every department, an area's the ones
 * that read that area. A provider that ignored the scope would draw the
 * organization's figures on an area's page, which is the one mistake a
 * director cannot see from the page.
 *
 * THE AREA IS AN IDENTIFIER, NOT AN ENTITY. A module answering here must
 * not have to know which class an installation resolved the area contract
 * to, and the uuid is what every seam in this platform passes.
 */
final readonly class PerformanceScope
{
    private function __construct(
        /** Null for the whole organization. */
        public ?string $areaUuid,
        /** What the page calls this scope: "Organization — all areas", or the area's name. */
        public string $label,
    ) {
    }

    public static function organization(string $label = 'Organization — all areas'): self
    {
        return new self(null, $label);
    }

    public static function area(string $areaUuid, string $label): self
    {
        return new self($areaUuid, $label);
    }

    public function isOrganization(): bool
    {
        return null === $this->areaUuid;
    }
}
