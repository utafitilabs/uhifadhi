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

namespace Uhifadhi\Bundle\TeamBundle\Exception;

/**
 * A position was handed a (concern, verb) pair that is neither declared by
 * anything installed here nor already granted on that position.
 *
 * IT IS AN EXCEPTION RATHER THAN A FILTER, and that is the whole point of it.
 * A write path that silently discards what it does not recognise lets a pair
 * a module declared be ticked, saved, and come back unticked with nothing
 * anywhere saying why. Refusing loudly turns that into a bug report on the
 * first run instead of a mystery on the hundredth.
 */
final class UnknownGrantException extends \InvalidArgumentException
{
    /**
     * @param list<string> $values the submitted pairs nothing declares
     */
    public function __construct(public readonly array $values)
    {
        parent::__construct(\sprintf(
            'Refusing to grant %s: %s declared by anything installed here, and not already held by this position. A resource exists because the bundle or module that enforces it declared it - check the permission, and check the module is installed.',
            implode(', ', array_map(static fn (string $v): string => '"'.$v.'"', $values)),
            1 === \count($values) ? 'it is not' : 'they are not',
        ));
    }
}
