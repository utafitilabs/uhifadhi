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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Uhifadhi\Contracts\Access\WholeOrganization;

/**
 * THE `door()` HELPER THE TEAM BUNDLE WOULD REGISTER.
 *
 * Every drawn control in the product asks `door('<concern>.<verb>', subject)`
 * rather than the checker directly, so that a conformance test can walk the
 * doors. The helper is TeamBundle's, and team is NOT a dependency of this
 * bundle: what these screens owe is that they ASK, and who answers is somebody
 * else's package.
 *
 * So the suite ships the smallest thing that answers, exactly as it does for
 * the voter beside it: the same functions, the same arguments, handing the
 * question to the authorization checker this kernel already has.
 */
final class DoorFunction extends AbstractExtension
{
    public function __construct(
        private readonly AuthorizationCheckerInterface $checker,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('door', $this->opens(...)),
            new TwigFunction('whole_organization', static fn (): WholeOrganization => new WholeOrganization()),
        ];
    }

    public function opens(string $pair, mixed $subject = null): bool
    {
        return $this->checker->isGranted($pair, $subject);
    }
}
