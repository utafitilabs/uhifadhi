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

/**
 * THE REFUSAL PAGE (ruled 30 Sep, #33, design D): a page somebody's position
 * does not reach answers 403 inside the shell, saying nothing about what was
 * asked for; a refused form post and the API keep their own answers.
 */
final class RefusalPageTest extends FieldApiTestCase
{
    public function testARefusedPageIsTheShellsNotAllowedPage(): void
    {
        $this->client->loginUser($this->officeStaff('Grace', 'Ndosi'));

        $page = $this->client->request('GET', '/settings');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('uhifadhi / not allowed', trim(preg_replace('/\s+/', ' ', $page->filter('.crumb')->text()) ?? ''));
        self::assertSame('There is nothing here you can open', $page->filter('.rf h2')->text());
        self::assertSame(['← Back', 'Your dashboard'], $page->filter('.rf .acts a')->each(static fn ($a): string => trim($a->text())));
        self::assertCount(1, $page->filter('header.topbar'), 'inside the shell, not a bare error page');
    }

    public function testARefusedPostKeepsItsOwnAnswer(): void
    {
        $this->client->loginUser($this->officeStaff('Grace', 'Ndosi'));

        $this->client->request('POST', '/settings');

        self::assertCount(0, $this->client->getCrawler()->filter('.rf'));
    }
}
