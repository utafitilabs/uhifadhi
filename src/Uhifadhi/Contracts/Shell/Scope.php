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

namespace Uhifadhi\Contracts\Shell;

/**
 * HOW WIDE A PAGE IS LOOKING — the organization, or one area of it.
 *
 * AN ORGANIZATION-LEVEL PAGE IS THE AREA PAGE ONE SCOPE WIDER, and this is
 * the whole of the difference: the module answers `forScope($scope)` where
 * the area page passes one area and the org page passes all of them. Two code
 * paths would drift, and the day they disagreed nobody would know which was
 * right — so there is one query and one argument.
 *
 * THE SHELL RESOLVES IT, from the control in the page's action row, and hands
 * it to the module. A module states no scope control of its own.
 *
 * A DEPARTMENT IS THE NEXT ONE, and it is deliberately not here yet: what a
 * department-scoped read means is a model question with the owner, and a
 * value object that carried an answer nobody had given would be the answer.
 * When it is ruled it is one more named constructor and one more branch in
 * whoever reads it — which is why the two that exist are asked by name
 * ({@see isOrganization()}) rather than by comparing a nullable uuid.
 */
final readonly class Scope
{
    private function __construct(
        /** The area this is about, or null for the whole organization. */
        public ?string $areaUuid,
        /** What the control says it is, in the installation's own words. */
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

    /** Whether this is the same slice as another — by what it names, never by its label. */
    public function is(self $other): bool
    {
        return $this->areaUuid === $other->areaUuid;
    }
}
