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

namespace Uhifadhi\Bundle\AreaBundle\Exception;

use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Bundle\AreaBundle\Enum\CheckInStatusKind;

/**
 * A SAVE OF AN AREA'S CHECK-IN STATUSES REFUSED, whole: nothing of the list
 * is stored. The message is the sentence the save bar says; `key` names the
 * new status whose key was the reason, so the editor can mark that row.
 */
final class CheckInStatusException extends \DomainException
{
    private function __construct(string $message, public readonly ?string $key = null)
    {
        parent::__construct($message);
    }

    public static function keyTaken(string $key): self
    {
        return new self(\sprintf('Not saved — another save took the key %s first. Choose another key; nothing else was lost.', $key), $key);
    }

    public static function keyTwice(string $key): self
    {
        return new self(\sprintf('Not saved — two new statuses send %s. Choose another key; nothing else was lost.', $key), $key);
    }

    public static function keyMalformed(string $key): self
    {
        return new self(\sprintf('Not saved — “%s” is not a key: lower-case letters, digits and _, starting with a letter, at most 32. Nothing else was lost.', $key), $key);
    }

    public static function unknown(string $key): self
    {
        return new self(\sprintf('Not saved — no status of this area sends %s. Reload the page; nothing was changed.', $key));
    }

    public static function sentTwice(string $key): self
    {
        return new self(\sprintf('Not saved — %s is sent twice. Reload the page; nothing was changed.', $key));
    }

    public static function nameless(): self
    {
        return new self('Not saved — every status needs a label. Nothing else was lost.');
    }

    public static function labelTooLong(): self
    {
        return new self(\sprintf('Not saved — a label is at most %d characters. Nothing else was lost.', CheckInStatus::LABEL_LENGTH));
    }

    public static function kindless(string $label): self
    {
        return new self(\sprintf('Not saved — “%s” needs a kind. Nothing else was lost.', $label));
    }

    public static function kindFixed(string $label, CheckInStatusKind $kind): self
    {
        return new self(\sprintf('Not saved — “%s” has check-ins, so it stays %s. Add a new status to mean something else; nothing else was lost.', $label, $kind->label()));
    }

    public static function frozen(string $label): self
    {
        return new self(\sprintf('Not saved — “%s” is not offered. Reactivate it to change it; nothing else was lost.', $label));
    }

    public static function noStation(): self
    {
        return new self(\sprintf('Not saved — at least one %s status stays offered. Nothing else was lost.', CheckInStatusKind::AtPost->label()));
    }
}
