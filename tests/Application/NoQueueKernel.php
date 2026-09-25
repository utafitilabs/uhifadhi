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

namespace Uhifadhi\Core\Tests\Application;

/**
 * THE SAME INSTALLATION WITH NO MESSENGER TRANSPORT CONFIGURED — the shape of
 * every module's test kernel. No Doctrine transport means no transport schema
 * listener, so what declares the queue's table to the schema tool here is the
 * registry alone.
 */
final class NoQueueKernel extends Kernel
{
    protected function configuresTheQueue(): bool
    {
        return false;
    }

    public function getCacheDir(): string
    {
        return $this->checkoutTempDir('application/cache-no-queue/'.$this->environment);
    }
}
