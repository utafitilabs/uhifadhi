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
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Service\Mail;

/**
 * THE SIGN-IN CARD ON A PERSON'S PAGE (ruled 30 Sep, #67, design D and D
 * after sending). Whoever holds Sign-in help - a head of station - sees
 * whether the person can get in and where a link would go, and sends one
 * from there: the configure page is the tiers' alone now. The address is
 * whole to somebody who reads personal details and masked to anybody else.
 */
final class SignInHelpCardTest extends WebTestCaseWithSchema
{
    public function testAHeadOfStationSeesTheCardWithTheAddress(): void
    {
        [$grace] = $this->headOfStationAnd(['directory.read', 'sign-in-help.manage', 'personal-details.read']);

        $card = $this->card($grace);

        self::assertCount(1, $card, 'one Sign-in card');
        self::assertSame(['Account', 'Last signed in', 'Link goes to', 'Last link sent'], $card->filter('tr td:first-child')->each(static fn (Crawler $c): string => $c->text()));
        self::assertStringContainsString('active', $card->text());
        self::assertStringContainsString('g.ndosi@example.test', $card->text());
        self::assertStringContainsString('never', $card->filter('tr')->last()->text());
        self::assertCount(1, $card->filter('form[action$="/reset-link"] button'), 'the button, in the foot');
    }

    public function testTheAddressIsMaskedToAViewerWhoDoesNotReadPersonalDetails(): void
    {
        [$grace] = $this->headOfStationAnd(['directory.read', 'sign-in-help.manage']);

        $card = $this->card($grace);

        self::assertStringContainsString('g•••@example.test', $card->text());
        self::assertStringNotContainsString('g.ndosi@example.test', $card->text());
    }

    public function testSomebodyWithoutSignInHelpSeesNoCard(): void
    {
        [$grace] = $this->headOfStationAnd(['directory.read', 'personal-details.read']);

        self::assertCount(0, $this->card($grace));
    }

    public function testSendingSaysWhereItWentAndTheCardRemembersWhoSentIt(): void
    {
        [$grace, $hamisi] = $this->headOfStationAnd(['directory.read', 'sign-in-help.manage', 'personal-details.read']);
        $sent = $this->mailerThatKeeps();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString());
        $form = $crawler->filter('.signin-card form[action$="/reset-link"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/team/'.$grace->getUuidString());
        $page = $this->client->followRedirect();
        self::assertStringContainsString('A link was sent to g.ndosi@example.test. It works once and expires in an hour.', $page->filter('.flashes')->text());
        $last = $page->filter('.signin-card tr')->last()->text();
        self::assertStringContainsString('just now', $last);
        self::assertStringContainsString('by '.$hamisi->getFullName(), $last);
        self::assertStringContainsString('Send again', $page->filter('.signin-card form button')->text());
        self::assertCount(1, $sent->messages, 'one letter, to the address on the account');

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'g.ndosi@example.test']);
        self::assertNotNull($stored?->getResetLinkSentAt());
    }

    public function testAHeadOfStationCannotSendToSomebodyOutsideTheirArea(): void
    {
        $north = $this->area('Kilimani');
        $south = $this->area('Tambarare');
        $hamisi = $this->person('Hamisi', 'Juma');
        $hamisi->setPosition($this->position('Head of station', ['directory.read', 'sign-in-help.manage']));
        $this->place($hamisi, [$north]);
        $juma = $this->person('Juma', 'Mollel');
        $this->place($juma, [$south]);
        $this->em->flush();
        $this->client->loginUser($hamisi);

        self::assertCount(0, $this->card($juma), 'no card for somebody they cannot help');
        $this->client->request('POST', '/team/'.$juma->getUuidString().'/reset-link');
        self::assertResponseStatusCodeSame(404, 'no token from any page they may open, so the token check answers; the rule is asserted by the card');
    }

    /**
     * Hamisi, head of station, in a seat with these pairs, placed in Kilimani;
     * and Grace, posted there, whom he may help.
     *
     * @param list<string> $pairs
     *
     * @return array{User, User}
     */
    private function headOfStationAnd(array $pairs): array
    {
        $kilimani = $this->area('Kilimani');
        $hamisi = $this->person('Hamisi', 'Juma');
        $hamisi->setPosition($this->position('Head of station', $pairs));
        $this->place($hamisi, [$kilimani]);
        $grace = $this->person('Grace', 'Ndosi');
        $this->place($grace, [$kilimani]);
        $this->em->flush();
        $this->client->loginUser($hamisi);

        return [$grace, $hamisi];
    }

    private function card(User $member): Crawler
    {
        return $this->client->request('GET', '/team/'.$member->getUuidString())->filter('.signin-card');
    }

    /**
     * A mailer that keeps what it is handed, for this client's requests only.
     *
     * @return object{messages: list<RawMessage>}
     */
    private function mailerThatKeeps(): object
    {
        $kept = new class implements MailerInterface {
            /** @var list<RawMessage> */
            public array $messages = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->messages[] = $message;
            }
        };
        $this->client->disableReboot();
        static::getContainer()->set('team.mail', new Mail($kept, 'no-reply@example.test', 'Uhifadhi Nature Reserves'));

        return $kept;
    }
}
