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

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Service\PositionService;
use Uhifadhi\Bundle\TeamBundle\Service\UserService;

/**
 * THE HISTORY CARD ON A RECORD READS THE PERMISSION HISTORY TOO (#75): a
 * person's record says when their tier, position or placement changed and
 * who changed it; a position's record says when what it grants changed.
 */
final class PermissionHistoryCardsTest extends WebTestCaseWithSchema
{
    private User $chief;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chief = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->em->flush();
    }

    public function testAPersonsRecordSaysWhoChangedTheirTier(): void
    {
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();
        $this->service(UserService::class)->changeTier($grace, TeamRoleEnum::Admin, $this->chief);

        $rows = $this->rows('/team/'.$grace->getUuidString());

        self::assertContains(['Made Admin', 'was Staff · by N. Kileo'], $rows);
    }

    public function testAPositionsRecordSaysWhenWhatItGrantsChanged(): void
    {
        $ranger = $this->position('Ranger');
        $this->em->flush();
        $this->service(PositionService::class)->setGrants($ranger, ['departments.read']);

        $rows = $this->rows('/team/positions/'.$ranger->getUuidString());

        self::assertContains(['Grants changed', '+ departments.read · by the system'], $rows);
    }

    /** @return list<array{0: string, 1: string}> */
    private function rows(string $url): array
    {
        $this->client->loginUser($this->chief);
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler->filter('.hlist .hrow')->each(static fn (Crawler $row): array => [
            trim($row->filter('.t')->text()),
            trim($row->filter('.by')->text()),
        ]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->client->getContainer()->get('test_public.'.$class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
