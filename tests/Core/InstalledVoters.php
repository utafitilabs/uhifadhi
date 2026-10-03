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

namespace Uhifadhi\Core\Tests\Core;

use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * EVERY VOTER THIS INSTALLATION HAS, as the access decision manager is given
 * them: the services tagged `security.voter`. The tag stays on the voter
 * itself in debug, where the decision manager is handed a traceable wrapper
 * registered under a separate id, so these are the voters and not their
 * wrappers.
 *
 * @see vendor/symfony/security-bundle/DependencyInjection/Compiler/AddSecurityVotersPass.php — reads the tag, wraps each voter in TraceableVoter under ".debug.security.voter.<id>" when kernel.debug is on
 */
final readonly class InstalledVoters
{
    /**
     * @param iterable<object> $voters
     */
    public function __construct(
        private iterable $voters,
    ) {
    }

    /**
     * @return list<VoterInterface>
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->voters as $voter) {
            if ($voter instanceof VoterInterface) {
                $all[] = $voter;
            }
        }

        return $all;
    }
}
