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

use Uhifadhi\Bundle\TeamBundle\Entity\User;

/**
 * A SIGN-IN ADDRESS IS A PERSONAL DETAIL. The People register names everybody
 * the directory reaches; whose address it shows whole is a question for
 * `personal-details.read`, the same one the sign-in card asks. Somebody
 * without it sees the address masked, and cannot find a person by it either:
 * a search that matched on an address the page hides would answer the
 * question the mask exists to refuse.
 */
final class AddressSightTest extends WebTestCaseWithSchema
{
    private const string ADDRESS = 'grace.ndosi@unr.example';
    private const string MASKED = 'g•••@unr.example';

    private User $grace;
    private User $directoryOnly;
    private User $personnelOfficer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grace = $this->person('Grace', 'Ndosi')->setEmail(self::ADDRESS);
        $this->place($this->grace);

        $this->directoryOnly = $this->person('Joseph', 'Mrema');
        $this->directoryOnly->setPosition($this->position('Station Head', ['directory.read']));
        $this->place($this->directoryOnly);

        $this->personnelOfficer = $this->person('Rehema', 'Kimaro');
        $this->personnelOfficer->setPosition($this->position('Personnel Officer', ['directory.read', 'personal-details.read']));
        $this->place($this->personnelOfficer);

        $this->em->flush();
    }

    public function testTheRegisterMasksAnAddressForSomebodyWhoMayNotReadIt(): void
    {
        $this->client->loginUser($this->directoryOnly);

        $crawler = $this->client->request('GET', '/team');
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString(self::ADDRESS, $body, 'the whole address reaches a reader without personal-details.read');
        self::assertStringContainsString(self::MASKED, $crawler->filter('main')->text(), 'the person is still listed, their address masked as the sign-in card masks it');
        self::assertCount(0, $crawler->filter('a[href^="mailto:"]'), 'a masked address is not a link to write to');
    }

    public function testTheRegisterShowsTheAddressToSomebodyWhoMayReadIt(): void
    {
        $this->client->loginUser($this->personnelOfficer);

        $crawler = $this->client->request('GET', '/team');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('a[href="mailto:'.self::ADDRESS.'"]'));
    }

    public function testNobodyFindsAPersonByAnAddressTheyMayNotRead(): void
    {
        $this->client->loginUser($this->directoryOnly);

        $crawler = $this->client->request('GET', '/team?q=ndosi@unr');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Grace', $crawler->filter('main')->text(), 'the search answered by an address this reader may not see');

        $crawler = $this->client->request('GET', '/team?q=Grace');
        self::assertStringContainsString('Grace', $crawler->filter('main')->text(), 'the name still finds her');
    }

    public function testSomebodyWhoMayReadAddressesFindsAPersonByOne(): void
    {
        $this->client->loginUser($this->personnelOfficer);

        $crawler = $this->client->request('GET', '/team?q=ndosi@unr');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Grace', $crawler->filter('main')->text());
    }
}
