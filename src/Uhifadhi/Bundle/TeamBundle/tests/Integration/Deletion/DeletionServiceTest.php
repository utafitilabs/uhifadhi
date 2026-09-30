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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Deletion;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Uhifadhi\Bundle\TeamBundle\Deletion\DeletionRefusedException;
use Uhifadhi\Bundle\TeamBundle\Deletion\DeletionService;
use Uhifadhi\Bundle\TeamBundle\Entity\DeletionRecord;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Contracts\Deletion\DeletionContributorInterface;
use Uhifadhi\Contracts\Deletion\DeletionLine;
use Uhifadhi\Contracts\Deletion\DeletionSubject;

/**
 * A SUPER ADMIN DELETES A RECORD AND EVERYTHING UNDER IT (ruled 28 Sep, #48):
 * counted first from whoever holds rows it reaches, confirmed by typing its
 * reference, removed in one transaction - the other contributors first, the
 * owner last - and one audit line kept: who, what, when.
 */
final class DeletionServiceTest extends IntegrationTestCase
{
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $calls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calls = new \ArrayObject();
    }

    public function testThePlanIsTheOwnersSubjectAndEveryContributorsLines(): void
    {
        $plan = $this->deletions(TeamRoleEnum::SuperAdmin)->plan(new \stdClass());

        self::assertSame('P-0142', $plan->subject->reference);
        self::assertSame(['1 patrol', '3 observations'], array_map(static fn (DeletionLine $l): string => $l->phrase(), $plan->goes));
        self::assertSame(['1 incident'], array_map(static fn (DeletionLine $l): string => $l->phrase(), $plan->stays));
    }

    public function testASuperAdminDeletesAndTheOthersGoBeforeTheOwnerAndOneLineIsKept(): void
    {
        $record = $this->deletions(TeamRoleEnum::SuperAdmin)->delete(new \stdClass(), 'P-0142');

        self::assertSame(['incident unlinks', 'patrol goes'], $this->calls->getArrayCopy());
        $this->em->clear();
        $kept = $this->em->getRepository(DeletionRecord::class)->findAll();
        self::assertCount(1, $kept);
        self::assertSame('Patrol P-0142 · Riverbend', $kept[0]->getTitle());
        self::assertSame('patrol', $kept[0]->getKind());
        self::assertSame('Naomi Kileo', $kept[0]->getByName());
        self::assertSame('1 patrol, 3 observations', $kept[0]->getWhatWent());
        self::assertSame($record->getId(), $kept[0]->getId());
    }

    public function testAWrongReferenceDeletesNothing(): void
    {
        try {
            $this->deletions(TeamRoleEnum::SuperAdmin)->delete(new \stdClass(), 'P-01');
            self::fail('A mistyped reference deleted the record.');
        } catch (DeletionRefusedException) {
        }

        self::assertSame([], $this->calls->getArrayCopy());
        self::assertSame([], $this->em->getRepository(DeletionRecord::class)->findAll());
    }

    public function testAnAdminIsRefusedAndNothingIsDeleted(): void
    {
        $this->expectException(AccessDeniedException::class);

        try {
            $this->deletions(TeamRoleEnum::Admin)->delete(new \stdClass(), 'P-0142');
        } finally {
            self::assertSame([], $this->calls->getArrayCopy());
        }
    }

    public function testARecordNobodyOwnsIsRefused(): void
    {
        $service = new DeletionService([], $this->em, $this->tokensFor(TeamRoleEnum::SuperAdmin));

        $this->expectException(\LogicException::class);
        $service->plan(new \stdClass());
    }

    private function deletions(TeamRoleEnum $tier): DeletionService
    {
        return new DeletionService([$this->incidentLinks(), $this->patrolOwner()], $this->em, $this->tokensFor($tier));
    }

    private function tokensFor(TeamRoleEnum $tier): TokenStorage
    {
        $naomi = (new User())->setEmail('n.kileo@example.test')->setFirstName('Naomi')->setLastName('Kileo')
            ->setPassword('x')->setTeamRole($tier)->setVerified(true);
        $this->em->persist($naomi);
        $this->em->flush();
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($naomi, 'main', $naomi->getRoles()));

        return $tokens;
    }

    private function patrolOwner(): DeletionContributorInterface
    {
        return new class($this->calls) implements DeletionContributorInterface {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private \ArrayObject $calls)
            {
            }

            public function supports(object $record): bool
            {
                return true;
            }

            public function describe(object $record): DeletionSubject
            {
                return new DeletionSubject('patrol', 'P-0142', 'Patrol P-0142 · Riverbend', 'Foot patrol', '/p', '/ps', 'patrols');
            }

            public function whatGoes(object $record): array
            {
                return [new DeletionLine('patrols', 1, singular: 'patrol'), new DeletionLine('observations', 3)];
            }

            public function whatStays(object $record): array
            {
                return [];
            }

            public function delete(object $record): void
            {
                $this->calls[] = 'patrol goes';
            }
        };
    }

    private function incidentLinks(): DeletionContributorInterface
    {
        return new class($this->calls) implements DeletionContributorInterface {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private \ArrayObject $calls)
            {
            }

            public function supports(object $record): bool
            {
                return true;
            }

            public function describe(object $record): ?DeletionSubject
            {
                return null;
            }

            public function whatGoes(object $record): array
            {
                return [];
            }

            public function whatStays(object $record): array
            {
                return [new DeletionLine('incidents', 1, singular: 'incident')];
            }

            public function delete(object $record): void
            {
                $this->calls[] = 'incident unlinks';
            }
        };
    }
}
