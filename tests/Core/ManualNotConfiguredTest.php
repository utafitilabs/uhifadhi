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

use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Core\Tests\Core\Fixtures\NoManualKernel;

/**
 * AN INSTALLATION THAT KEEPS NO MANUAL HAS NONE: every manual address answers
 * 404 to whoever is signed in, and the sidebar draws no row and no Help group.
 */
final class ManualNotConfiguredTest extends FieldApiTestCase
{
    protected static function getKernelClass(): string
    {
        return NoManualKernel::class;
    }

    public function testEveryAddressIsNotFoundAndTheSidebarHasNoManual(): void
    {
        $this->client->loginUser($this->officeStaff('Neema', 'Mollel'));

        foreach (['/manual/', '/manual/img/cover.png', '/manual/manual.pdf'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $path);
        }

        $page = $this->client->request('GET', '/me/profile');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('nav.nav a[href^="/manual"]'));
        self::assertNotContains('Help', $page->filter('nav.nav .nav-hd')->each(static fn ($c): string => $c->text()));
    }
}
