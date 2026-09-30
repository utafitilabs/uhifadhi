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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Functional;

use Uhifadhi\Bundle\TeamBundle\Entity\DeletionRecord;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * A SUPER ADMIN DELETES A PERSON (ruled 28 Sep, #48, design C): a Delete row
 * on their configure page, a page of its own that counts what goes, the name
 * typed to confirm, and one audit line kept. Nobody else sees the row or the
 * page, and nobody deletes themselves.
 */
final class PersonDeletionTest extends WebTestCaseWithSchema
{
    public function testASuperAdminDeletesAPersonAndOneLineIsKept(): void
    {
        $this->administrator();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $configure = $this->client->request('GET', '/team/'.$grace->getUuidString().'/configure');
        self::assertCount(1, $configure->filter('a[href$="/team/'.$grace->getUuidString().'/delete"]'), 'the Delete row');

        $page = $this->client->request('GET', '/team/'.$grace->getUuidString().'/delete');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Delete person Grace Ndosi', $page->filter('h1')->text());
        self::assertSame(['1'], $page->filter('.dcounts .dcnt b')->each(static fn ($c): string => $c->text()));
        self::assertSame(['account · g.ndosi@example.test'], $page->filter('.dcounts .dcnt span')->each(static fn ($c): string => $c->text()));
        self::assertStringContainsString('Type Grace Ndosi to delete this person', $page->filter('.dconfirm label')->text());

        $form = $page->filter('form.dconfirm')->form(['reference' => 'Grace Ndosi']);
        $this->client->submit($form);

        self::assertResponseRedirects('/team');
        $this->em->clear();
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'g.ndosi@example.test']));
        $kept = $this->em->getRepository(DeletionRecord::class)->findAll();
        self::assertCount(1, $kept);
        self::assertSame('Person Grace Ndosi', $kept[0]->getTitle());
        self::assertSame('Naomi Kileo', $kept[0]->getByName());
        self::assertStringContainsString('1 account', $kept[0]->getWhatWent());
    }

    public function testAMistypedNameDeletesNothing(): void
    {
        $this->administrator();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $page = $this->client->request('GET', '/team/'.$grace->getUuidString().'/delete');
        $this->client->submit($page->filter('form.dconfirm')->form(['reference' => 'grace ndosi']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('type Grace Ndosi exactly', $this->client->getCrawler()->filter('.dwrong')->text());
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(User::class)->findOneBy(['email' => 'g.ndosi@example.test']));
    }

    public function testAnAdminNeitherSeesTheRowNorOpensThePage(): void
    {
        $admin = $this->person('Desta', 'Haile', TeamRoleEnum::Admin);
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();
        $this->client->loginUser($admin);

        $configure = $this->client->request('GET', '/team/'.$grace->getUuidString().'/configure');
        self::assertCount(0, $configure->filter('a[href$="/delete"]'));
        $this->client->request('GET', '/team/'.$grace->getUuidString().'/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testNobodyDeletesThemselves(): void
    {
        $naomi = $this->administrator();

        $this->client->request('GET', '/team/'.$naomi->getUuidString().'/delete');

        self::assertResponseRedirects('/team/'.$naomi->getUuidString());
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(User::class)->findOneBy(['email' => 'n.kileo@example.test']));
    }
}
