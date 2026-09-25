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

namespace Uhifadhi\Bundle\AreaBundle\Settings;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Contracts\Settings\CheckVerdict;
use Uhifadhi\Contracts\Settings\SettingsCheck;
use Uhifadhi\Contracts\Settings\SettingsCheckSourceInterface;

/**
 * "DOES EVERY AREA RUN SOMETHING?" — the installation screen's reading of
 * {@see AreaSetup}.
 *
 * SEPARATE FROM THE QUEUE'S READING, and not by preference: a class cannot
 * implement two contract interfaces that each publish a `TAG` constant, which
 * PHP refuses outright. The pair share their answer through the reading they
 * are both given, so the split costs nothing but the file.
 *
 * NOTHING TO CHECK IS NOT A FAILING CHECK. An installation with no areas at
 * all has not got this wrong; it has not got here yet, so the row is absent
 * rather than red.
 */
final readonly class AreaSetupCheck implements SettingsCheckSourceInterface
{
    /** Among the last of the checks: it is about setup, not about running. */
    public const int POSITION = 60;

    public function __construct(
        private AreaSetup $setup,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function position(): int
    {
        return self::POSITION;
    }

    public function settingsChecks(): iterable
    {
        // IT NAMES AREAS, so it asks what reading one asks.
        if (!$this->authorization->isGranted(AreaFigure::READ) || 0 === $this->setup->areas()) {
            return;
        }

        $waiting = \count($this->setup->waiting());

        yield new SettingsCheck(
            'areas-run-a-module',
            0 === $waiting ? CheckVerdict::Pass : CheckVerdict::Check,
            0 === $waiting
                ? 'Every area runs at least one module'
                : \sprintf('%d area%s run no module', $waiting, 1 === $waiting ? '' : 's'),
            0 === $waiting
                ? \sprintf('%d areas · each reporting through at least one module', $this->setup->areas())
                : $this->setup->namesWaiting(),
        );
    }
}
