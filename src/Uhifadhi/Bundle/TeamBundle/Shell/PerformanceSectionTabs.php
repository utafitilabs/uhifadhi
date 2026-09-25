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

namespace Uhifadhi\Bundle\TeamBundle\Shell;

use Symfony\Component\HttpFoundation\RequestStack;
use Uhifadhi\Bundle\TeamBundle\Access\Door;
use Uhifadhi\Bundle\TeamBundle\Controller\DepartmentController;
use Uhifadhi\Bundle\TeamBundle\Controller\PerformanceController;
use Uhifadhi\Contracts\Shell\ModuleTab;
use Uhifadhi\Contracts\Shell\ModuleTabsInterface;

/**
 * THE PERFORMANCE SECTION'S TAB SET — Overview · Topics · Briefing.
 *
 * THE SAME CONTRACT THE DEPARTMENTS SECTION USES, and the reason is the
 * ruling that a section wears the AREA idiom: the strip, the header and
 * the one Configure action are the frame's to draw, and a section that
 * built its own would be the one place in the product where they moved.
 *
 * THIS REPLACED A HAND-BUILT STRIP. The performance pages were passing
 * their own list of tabs into the shell's partial — which worked, and
 * meant Configure never appeared, because the frame draws that only for
 * a surface it recognises.
 *
 * A TOPIC'S RECORD IS NOT IN THE STRIP AND GETS NONE, exactly as a
 * department's record does not: it is a thing inside the section rather
 * than one of its screens, it is headed by the topic's own name, and
 * the sidebar's subtree is what says where you are.
 */
final readonly class PerformanceSectionTabs implements ModuleTabsInterface
{
    /**
     * The surface this section is addressed by. Route defaults name it,
     * and the shell resolves the strip, the configure sections and the
     * action from it.
     */
    public const string SURFACE = 'performance';

    /**
     * WHAT THE READER IS LOOKING AT, as opposed to how they are looking
     * at it: the three that travel from screen to screen.
     */
    private const array READING = ['area', 'period', 'compare'];

    public function slug(): string
    {
        return self::SURFACE;
    }

    public function __construct(
        private RequestStack $requests,
        private Door $door,
    ) {
    }

    public function tabs(): array
    {
        // ALL THREE OR NONE: every screen of the section enforces one pair.
        if (!$this->door->opens(DepartmentController::READ)) {
            return [];
        }

        /*
         * THE READING TRAVELS WITH THE READER. Moving from the Overview
         * to Topics is a change of SCREEN and never of subject, so the
         * scope, the window and the comparison ride on every tab — a
         * strip that dropped them would answer "the organization's
         * August" with "the organization's this month" halfway through
         * somebody's reading, and nothing on the page would say why.
         *
         * THE SECTION CARRIES THEM AND NOT THE SHELL, because which of
         * a page's parameters are the SUBJECT is the section's own
         * knowledge: a strip that forwarded every query string would
         * carry a sort order and a page number across screens that have
         * neither.
         */
        $reading = [];
        $request = $this->requests->getCurrentRequest();
        foreach (self::READING as $name) {
            $value = $request?->query->get($name);
            if (\is_string($value) && '' !== $value) {
                $reading[$name] = $value;
            }
        }

        return [
            new ModuleTab('Overview', PerformanceController::ROUTE, $reading),
            new ModuleTab('Topics', PerformanceController::TOPICS_ROUTE, $reading),
            new ModuleTab('Briefing', PerformanceController::BRIEFING_ROUTE, $reading),
        ];
    }
}
