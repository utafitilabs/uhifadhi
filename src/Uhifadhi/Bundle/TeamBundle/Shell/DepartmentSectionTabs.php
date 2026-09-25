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

use Uhifadhi\Bundle\TeamBundle\Access\Door;
use Uhifadhi\Bundle\TeamBundle\Controller\DepartmentController;
use Uhifadhi\Bundle\TeamBundle\Controller\DepartmentSectionController;
use Uhifadhi\Contracts\Shell\ModuleTab;
use Uhifadhi\Contracts\Shell\ModuleTabsInterface;

/**
 * THE DEPARTMENTS SECTION'S TAB SET — Overview · Departments · Modules.
 *
 * A SECTION WEARS THE AREA IDIOM, and the cheapest way to mean that is to use
 * the same contract an area's modules use rather than to grow a second one. The
 * surface slug is not a module slug: nothing in the registry answers for
 * "departments", and the route gate only closes a declared route that also
 * names an area, which none of these do. So the marker buys the frame — the
 * strip, the header, the one Configure action — and costs nothing else.
 *
 * CONFIGURE IS NOT IN THIS LIST. It is an action at the right-hand end of the
 * header on every one of these tabs, written by the frame; a section that put
 * it in the strip would be the only place in the product where it moved.
 *
 * THE RECORD IS NOT IN THE STRIP AND GETS NONE. A department's own page is a
 * place of its own — it is headed by the department's name, not the section's,
 * and carries its own tabs — so it is inside the section rather than being one
 * of its screens, and the shell draws no section strip on it. The sidebar's
 * subtree is what says where you are there.
 */
final readonly class DepartmentSectionTabs implements ModuleTabsInterface
{
    /**
     * The surface this section is addressed by. Route defaults name it, and the
     * shell resolves the strip, the configure sections and the action from it.
     */
    public const string SURFACE = 'departments';

    public function __construct(private Door $door)
    {
    }

    public function slug(): string
    {
        return self::SURFACE;
    }

    /**
     * ALL THREE OR NONE: every screen of the section enforces the one pair,
     * so a viewer without it is offered no strip at all.
     */
    public function tabs(): array
    {
        if (!$this->door->opens(DepartmentController::READ)) {
            return [];
        }

        return [
            new ModuleTab('Overview', DepartmentSectionController::OVERVIEW),
            new ModuleTab('Departments', DepartmentController::REGISTER),
            new ModuleTab('Modules', DepartmentSectionController::MODULES),
        ];
    }
}
