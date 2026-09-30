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

namespace Uhifadhi\Bundle\ShellBundle\Twig;

use Uhifadhi\Bundle\ShellBundle\Contract\DeletionPageInterface;

/**
 * `may_delete()` - whether the signed-in account may delete at all, the
 * question every Danger card's Delete row asks (ruled 28 Sep, #48). False
 * where no delete page is installed, so a template never needs to know
 * whether the Team is.
 */
final readonly class DeletionRuntime
{
    public function __construct(private ?DeletionPageInterface $page = null)
    {
    }

    public function mayDelete(): bool
    {
        return $this->page?->mayDelete() ?? false;
    }
}
