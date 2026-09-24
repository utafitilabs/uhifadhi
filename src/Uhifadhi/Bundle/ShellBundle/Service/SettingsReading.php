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

namespace Uhifadhi\Bundle\ShellBundle\Service;

use Psr\Container\ContainerInterface;
use Uhifadhi\Bundle\ShellBundle\Model\CorePart;
use Uhifadhi\Bundle\ShellBundle\Model\InstalledPackage;
use Uhifadhi\Contracts\Settings\CheckVerdict;
use Uhifadhi\Contracts\Settings\DecisionUrgency;
use Uhifadhi\Contracts\Settings\ModuleColumn;
use Uhifadhi\Contracts\Settings\ModuleMatrix;
use Uhifadhi\Contracts\Settings\ModuleMatrixSourceInterface;
use Uhifadhi\Contracts\Settings\OrganizationIdentity;
use Uhifadhi\Contracts\Settings\OrganizationIdentitySourceInterface;
use Uhifadhi\Contracts\Settings\SettingsChange;
use Uhifadhi\Contracts\Settings\SettingsChangeSourceInterface;
use Uhifadhi\Contracts\Settings\SettingsCheck;
use Uhifadhi\Contracts\Settings\SettingsCheckSourceInterface;
use Uhifadhi\Contracts\Settings\SettingsDecision;
use Uhifadhi\Contracts\Settings\SettingsDecisionSourceInterface;
use Uhifadhi\Contracts\Settings\SettingsFigure;
use Uhifadhi\Contracts\Settings\SettingsFigureSourceInterface;
use Uhifadhi\Contracts\Settings\SettingsStep;
use Uhifadhi\Contracts\Settings\SettingsStepSourceInterface;

/**
 * EVERYTHING THE SETTINGS SCREENS DRAW, COMPOSED FROM WHOEVER OWNS IT.
 *
 * THE SECTION KNOWS THE INSTALLATION AND NOTHING IN IT. What it can read for
 * itself is Composer's runtime metadata — the packages on disk and their
 * versions — and that is the whole of its own knowledge. Areas, people, what
 * is kept, which modules run where, whose organization this is: every one of
 * those arrives through a contract, from the bundle that owns the fact, which
 * is why this class names no module and asks no repository.
 *
 * THE TEMPLATES PULL, RATHER THAN THE CONTROLLER PUSHING. Four screens draw
 * four different subsets of this and one address serves all four, so a
 * controller that resolved everything would run the health checks to draw the
 * identity card. Each answer is computed on first ask and kept for the
 * request, so two cards reading the same figure read one measurement rather
 * than two taken a moment apart.
 *
 * A SOURCE THAT FAILS DOES NOT TAKE THE PAGE. This is the one screen somebody
 * opens to find out whether anything is wrong; a check that threw would take
 * down the report of itself. So a throwing check source becomes a row saying
 * it could not be run, and every other kind of source is simply skipped —
 * loudly in the logs, silently on a page that has other things to say.
 */
final class SettingsReading
{
    /** How many changes the overview's last card carries before "view all". */
    public const int CHANGES = 4;

    /** @var list<SettingsFigure>|null */
    private ?array $figures = null;

    /** @var list<SettingsCheck>|null */
    private ?array $checks = null;

    /** @var list<SettingsDecision>|null */
    private ?array $decisions = null;

    /** @var list<SettingsChange>|null */
    private ?array $changes = null;

    /** @var list<SettingsStep>|null */
    private ?array $steps = null;

    private ?ModuleMatrix $matrix = null;

    private ?OrganizationIdentity $identity = null;

    private ?OrganizationIdentity $organization = null;

    /** Null is an ANSWER here — nobody has named this installation — so the ask is remembered separately. */
    private bool $organizationAsked = false;

    /**
     * @param iterable<SettingsFigureSourceInterface>   $figureSources
     * @param iterable<SettingsCheckSourceInterface>    $checkSources
     * @param iterable<SettingsDecisionSourceInterface> $decisionSources
     * @param iterable<SettingsChangeSourceInterface>   $changeSources
     * @param iterable<SettingsStepSourceInterface>     $stepSources
     * @param ContainerInterface                        $singles         the two facts that have ONE answer, not a collected many
     * @param array<string, string>                     $bundles         the kernel's registered bundles, name => class name
     */
    public function __construct(
        private readonly Installation $installation,
        private readonly iterable $figureSources,
        private readonly iterable $checkSources,
        private readonly iterable $decisionSources,
        private readonly iterable $changeSources,
        private readonly iterable $stepSources,
        private readonly ContainerInterface $singles,
        private readonly string $brandName,
        private readonly array $bundles = [],
    ) {
    }

    /**
     * WHAT IS HERE WHETHER A MODULE IS INSTALLED OR NOT — the parts of the
     * core, each quoting its own manifest.
     *
     * READ, NEVER TYPED. A hand-written list of what the platform gives you
     * is wrong the first time the core grows a part, on the one page whose
     * job is to say what this installation has.
     *
     * INSTALLED MEANS REGISTERED. A directory this kernel does not boot is a
     * directory, and telling somebody otherwise is telling them a screen
     * exists where none does.
     *
     * @return list<CorePart>
     */
    public function coreParts(): array
    {
        return $this->installation->coreParts($this->installation->coreInstallPath(), $this->bundles);
    }

    /**
     * WHAT A MODULE ADDS — installed once, switched on per area.
     *
     * The matrix's own columns, because that is where the modules are known:
     * this card and the table on the next screen cannot then disagree about
     * what is installed.
     *
     * @return list<ModuleColumn>
     */
    public function modules(): array
    {
        return $this->matrix()->columns;
    }

    /**
     * WHAT IS LEFT TO SET UP, AND WHERE THIS INSTALLATION IS ON EACH.
     *
     * EVERGREEN. Not one row says whether the installation has begun; each
     * states where it has got to, so the list is as worth opening in year
     * three as in week one — which is the whole reason the page it sits on
     * replaced a welcome screen.
     *
     * @return list<SettingsStep>
     */
    public function steps(): array
    {
        if (null !== $this->steps) {
            return $this->steps;
        }

        $sources = [];
        foreach ($this->stepSources as $source) {
            $sources[] = $source;
        }

        usort($sources, static fn (SettingsStepSourceInterface $a, SettingsStepSourceInterface $b): int => $a->position() <=> $b->position());

        $steps = [];
        foreach ($sources as $source) {
            foreach ($source->settingsSteps() as $step) {
                $steps[] = $step;
            }
        }

        return $this->steps = $steps;
    }

    /** How many of the steps have nothing outstanding today. */
    public function stepsDone(): int
    {
        return \count(array_filter($this->steps(), static fn (SettingsStep $step): bool => $step->done));
    }

    /**
     * THE OVERVIEW'S FIGURE ROW — four to a row, and not one of them the
     * section's own.
     *
     * @return list<SettingsFigure>
     */
    public function figures(): array
    {
        if (null !== $this->figures) {
            return $this->figures;
        }

        $sources = [];
        foreach ($this->figureSources as $source) {
            $sources[] = $source;
        }

        usort($sources, static fn (SettingsFigureSourceInterface $a, SettingsFigureSourceInterface $b): int => $a->position() <=> $b->position());

        $figures = [];
        foreach ($sources as $source) {
            foreach ($source->settingsFigures() as $figure) {
                $figures[] = $figure;
            }
        }

        return $this->figures = $figures;
    }

    /**
     * THE INSTALLATION SCREEN'S FIGURE ROW, and this one IS the section's
     * own: what it runs, at what version, how its checks came out, and when
     * it last changed.
     *
     * TWO OF THE FOUR STATE THEIR OWN ABSENCE on an ordinary installation, and
     * that is the honest reading rather than a gap. Whether a package is
     * BEHIND needs a release feed to compare against, and when the
     * installation last DEPLOYED needs whatever deployed it to have said so;
     * neither is something a running application can read about itself, and a
     * card that filled them in would be guessing on a screen whose whole job
     * is not to.
     *
     * @return list<SettingsFigure>
     */
    public function installationFigures(): array
    {
        $checks = $this->checks();
        $passed = \count(array_filter($checks, static fn (SettingsCheck $check): bool => $check->passed()));

        return [
            $this->modulesFigure(),
            new SettingsFigure(
                'core',
                'Core',
                $this->coreVersion(),
                caption: null === $this->coreVersion()
                    ? 'composer cannot say which version is installed'
                    : 'the version this installation runs',
            ),
            new SettingsFigure(
                'health',
                'Health checks',
                [] === $checks ? null : (string) \count($checks),
                caption: [] === $checks
                    ? 'nothing installed here publishes a check yet'
                    : \sprintf('%d pass', $passed),
                warning: [] === $checks || \count($checks) === $passed ? null : \sprintf('%d to check', \count($checks) - $passed),
            ),
            new SettingsFigure(
                'deploy',
                'Last deploy',
                null,
                caption: 'nothing here records a deploy — an application that does publishes it as a change',
            ),
        ];
    }

    /**
     * WHAT IS INSTALLED — every uhifadhi package on disk, read from Composer
     * at render time.
     *
     * @return list<InstalledPackage>
     */
    public function packages(): array
    {
        return $this->installation->packages();
    }

    /** The core's own version, or null where Composer cannot say. */
    public function coreVersion(): ?string
    {
        foreach ($this->packages() as $package) {
            if (Installation::CORE === $package->name) {
                return $package->version;
            }
        }

        return null;
    }

    /**
     * THE HEALTH LIST — every question anybody installed here can answer,
     * in the order they declared.
     *
     * @return list<SettingsCheck>
     */
    public function checks(): array
    {
        if (null !== $this->checks) {
            return $this->checks;
        }

        $sources = [];
        foreach ($this->checkSources as $source) {
            $sources[] = $source;
        }

        usort($sources, static fn (SettingsCheckSourceInterface $a, SettingsCheckSourceInterface $b): int => $a->position() <=> $b->position());

        $checks = [];
        foreach ($sources as $source) {
            try {
                foreach ($source->settingsChecks() as $check) {
                    $checks[] = $check;
                }
            } catch (\Throwable $failure) {
                // A CHECK THAT CANNOT BE RUN IS A FINDING, not an outage. The
                // row says so with the reason, which is more than the screen
                // would have said if the source had simply been skipped.
                $checks[] = new SettingsCheck(
                    'unavailable-'.\count($checks),
                    CheckVerdict::Check,
                    'A check could not be run',
                    $failure->getMessage(),
                );
            }
        }

        return $this->checks = $checks;
    }

    /** How many of the checks are satisfied — the caption the list carries. */
    public function checksPassed(): int
    {
        return \count(array_filter($this->checks(), static fn (SettingsCheck $check): bool => $check->passed()));
    }

    /**
     * WHAT NEEDS A DECISION, sorted by urgency across its sources rather than
     * grouped by the bundle that noticed.
     *
     * @return list<SettingsDecision>
     */
    public function decisions(): array
    {
        if (null !== $this->decisions) {
            return $this->decisions;
        }

        $decisions = [];
        foreach ($this->decisionSources as $source) {
            foreach ($source->settingsDecisions() as $decision) {
                $decisions[] = $decision;
            }
        }

        // STABLE WITHIN ONE URGENCY, so two sources cannot reorder each
        // other's rows between requests for no reason a reader can see.
        $order = array_flip(array_column(DecisionUrgency::cases(), 'value'));
        usort($decisions, static fn (SettingsDecision $a, SettingsDecision $b): int => $order[$a->urgency->value] <=> $order[$b->urgency->value]);

        return $this->decisions = $decisions;
    }

    /**
     * WHAT CHANGED RECENTLY — the latest few, newest first, merged across
     * everybody who records one.
     *
     * @return list<SettingsChange>
     */
    public function changes(): array
    {
        if (null !== $this->changes) {
            return $this->changes;
        }

        $changes = [];
        foreach ($this->changeSources as $source) {
            foreach ($source->settingsChanges(self::CHANGES) as $change) {
                $changes[] = $change;
            }
        }

        usort($changes, static fn (SettingsChange $a, SettingsChange $b): int => $b->when <=> $a->when);

        return $this->changes = \array_slice($changes, 0, self::CHANGES);
    }

    /**
     * WHAT RUNS WHERE, or an empty matrix on an installation whose areas
     * nobody owns — which is a reading, not a failure.
     */
    public function matrix(): ModuleMatrix
    {
        if (null !== $this->matrix) {
            return $this->matrix;
        }

        $source = $this->single(ModuleMatrixSourceInterface::SERVICE, ModuleMatrixSourceInterface::class);

        return $this->matrix = $source instanceof ModuleMatrixSourceInterface
            ? $source->moduleMatrix()
            : new ModuleMatrix();
    }

    /**
     * WHOSE INSTALLATION THIS IS.
     *
     * THE FALLBACK IS THE WORDMARK IT WAS SHIPPED WITH, and every other field
     * says it is not set — which is the page telling somebody exactly what
     * there is to do rather than inventing an organization nobody named.
     */
    public function identity(): OrganizationIdentity
    {
        return $this->identity ??= $this->organization() ?? new OrganizationIdentity($this->brandName);
    }

    /**
     * WHOSE INSTALLATION THIS IS, OR NULL BECAUSE NOBODY HAS SAID — the same
     * reading as {@see self::identity()} with the fallback taken off.
     *
     * The distinction is the top bar's: the settings screen draws a fallback
     * because a table cell must hold something, and the bar draws nothing,
     * because repeating the wordmark the sidebar head already carries would
     * state the organization in two slots and name it in neither.
     */
    public function organization(): ?OrganizationIdentity
    {
        if ($this->organizationAsked) {
            return $this->organization;
        }

        $this->organizationAsked = true;
        $source = $this->single(OrganizationIdentitySourceInterface::SERVICE, OrganizationIdentitySourceInterface::class);

        return $this->organization = $source instanceof OrganizationIdentitySourceInterface
            ? $source->organizationIdentity()
            : null;
    }

    /**
     * THE SECTION'S OWN FIRST CARD, WHICH IS NOT ITS OWN AT ALL: how many
     * modules run here is the catalogue's answer, and the catalogue belongs
     * to whoever keeps it. The card is here because the row is four wide
     * whoever fills it; the number arrives through the same tag as the rest.
     */
    private function modulesFigure(): SettingsFigure
    {
        foreach ($this->figures() as $figure) {
            if ($figure->hot) {
                return $figure;
            }
        }

        return new SettingsFigure(
            'modules',
            'Modules installed',
            null,
            caption: 'nothing here keeps a catalogue of them',
            hot: true,
        );
    }

    /**
     * ONE OF THE TWO FACTS THAT HAVE A SINGLE ANSWER. An alias, not a tagged
     * collection: two things claiming to know which modules run where, or
     * whose installation this is, is exactly the disagreement the contracts
     * exist to prevent — see both source interfaces.
     *
     * @param class-string $expected
     */
    private function single(string $id, string $expected): ?object
    {
        if (!$this->singles->has($id)) {
            return null;
        }

        $source = $this->singles->get($id);

        return $source instanceof $expected ? $source : null;
    }
}
