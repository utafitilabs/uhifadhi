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

namespace Uhifadhi\Bundle\TeamBundle\Deletion;

/** A delete that did not happen, and the sentence the page says about it. */
final class DeletionRefusedException extends \RuntimeException
{
    public static function referenceMismatch(string $reference): self
    {
        return new self(\sprintf('Nothing was deleted: type %s exactly to confirm.', $reference));
    }
}
