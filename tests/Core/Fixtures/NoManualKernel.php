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

namespace Uhifadhi\Core\Tests\Core\Fixtures;

use Uhifadhi\Core\Tests\Application\Kernel;

/**
 * THE THROWAWAY APPLICATION WITHOUT A MANUAL — a fresh installation, which
 * names no folder under `shell.manual`. Its own cache directory, so the
 * compiled container without the manual is never mistaken for the one with.
 */
final class NoManualKernel extends Kernel
{
    protected function manualFolder(): ?string
    {
        return null;
    }

    public function getCacheDir(): string
    {
        return $this->checkoutTempDir('application/cache/'.$this->environment.'-no-manual');
    }
}
