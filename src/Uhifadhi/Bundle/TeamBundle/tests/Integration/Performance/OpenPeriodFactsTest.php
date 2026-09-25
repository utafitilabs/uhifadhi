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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Performance;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleCategory;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleStatus;
use Uhifadhi\Bundle\RegistryBundle\Repository\FigureFactRepository;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentPerformance;
use Uhifadhi\Bundle\TeamBundle\Service\PerformanceHistory;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Contracts\Facts\FactValue;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;

/**
 * THE TRANSITION RULE: a department figure is read from the facts ledger
 * when the worker has filed one, and from the module's live answer when it
 * has not.
 *
 * A module moves its figures onto the ledger one at a time. It declares a
 * figure through its fact provider, filed under the department as
 * `<module>.<key>` — the name the performance history already uses — and
 * from the first run on, the ledger's number is the one the page shows.
 * Until then nothing changes.
 */
#[CoversClass(DepartmentPerformance::class)]
#[CoversClass(PerformanceHistory::class)]
final class OpenPeriodFactsTest extends IntegrationTestCase
{
    private const string NOW = '2026-09-25 14:00:00';

    public function testWithoutAFactTheModulesLiveAnswerStands(): void
    {
        $department = $this->aDepartmentAttachingSurveys();

        $surveys = $this->kpi($department, 'surveys');

        self::assertSame(88.0, $surveys->value);
        self::assertSame(79.0, $surveys->previous);
        self::assertNull($surveys->asOf);
    }

    public function testAFiledFigureIsReadInsteadAndCarriesItsTime(): void
    {
        $department = $this->aDepartmentAttachingSurveys();
        $this->file($department, 'surveys.surveys', '2026-09', 91.0, '2026-09-25 13:00:00');

        $surveys = $this->kpi($department, 'surveys');

        self::assertSame(91.0, $surveys->value);
        self::assertSame(79.0, $surveys->previous, 'no fact for August: the module’s own comparison stands');
        self::assertNotNull($surveys->asOf);
        self::assertSame('2026-09-25 13:00', $surveys->asOf->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i'));
        self::assertSame('Surveys logged', $surveys->label, 'what the module says about the figure is kept');
    }

    public function testThePeriodBeforeIsReadFromTheLedgerToo(): void
    {
        $department = $this->aDepartmentAttachingSurveys();
        $this->file($department, 'surveys.surveys', '2026-09', 91.0, '2026-09-25 13:00:00');
        $this->file($department, 'surveys.surveys', '2026-08', 70.0, '2026-09-01 02:00:00');

        self::assertSame(70.0, $this->kpi($department, 'surveys')->previous);
    }

    /** A figure the module answers as unknown is filled by the ledger once the worker has computed it. */
    public function testAnUnknownLiveFigureIsFilledByTheLedger(): void
    {
        $department = $this->aDepartmentAttachingSurveys();
        $this->file($department, 'surveys.coverage', '2026-09', 54.0, '2026-09-25 13:00:00');

        self::assertSame(54.0, $this->kpi($department, 'coverage')->value);
    }

    public function testAFactOfAnotherMonthIsNotThisMonths(): void
    {
        $department = $this->aDepartmentAttachingSurveys();
        $this->file($department, 'surveys.surveys', '2026-07', 12.0, '2026-08-01 02:00:00');

        self::assertSame(88.0, $this->kpi($department, 'surveys')->value);
    }

    /** The history keeps its closed periods, and the open one it never wrote is read from the ledger. */
    public function testTheHistoryReadsTheOpenPeriodFromTheLedger(): void
    {
        $department = $this->aDepartmentAttachingSurveys();
        $history = $this->service(PerformanceHistory::class);

        $history->record($department, '2026-08', 'surveys.surveys', 70.0);
        $this->file($department, 'surveys.surveys', '2026-08', 99.0, '2026-09-01 02:00:00');
        $this->file($department, 'surveys.surveys', '2026-09', 91.0, '2026-09-25 13:00:00');

        self::assertSame(
            ['2026-07' => null, '2026-08' => 70.0, '2026-09' => 91.0],
            $history->runFor($department, 'surveys.surveys', ['2026-07', '2026-08', '2026-09']),
            'a written period is what was written; a period nobody wrote is the ledger’s, or a hole',
        );
        self::assertSame(91.0, $history->valueAt($department, 'surveys.surveys', '2026-09'));
        self::assertSame(70.0, $history->valueAt($department, 'surveys.surveys', '2026-08'));
    }

    private function kpi(Department $department, string $key): DepartmentKpi
    {
        foreach ($this->service(DepartmentPerformance::class)->kpisFor($department, new \DateTimeImmutable(self::NOW)) as $kpi) {
            if ($key === $kpi->key) {
                return $kpi;
            }
        }

        self::fail(\sprintf('No "%s" figure reached the department.', $key));
    }

    private function file(Department $department, string $figure, string $periodKey, ?float $value, string $at): void
    {
        $this->service(FigureFactRepository::class)->upsert(
            [new FactValue(FactSubject::DEPARTMENT, (string) $department->getUuidString(), $figure, $value)],
            $periodKey,
            new \DateTimeImmutable($at),
        );
    }

    private function aDepartmentAttachingSurveys(): Department
    {
        $department = new Department()->setName('Ecology');
        $this->em->persist($department);

        $module = new Module()
            ->setSlug('surveys')
            ->setName('Surveys')
            ->setCategory(ModuleCategory::Pressure)
            ->setStatus(ModuleStatus::Live)
            ->setDataSource('the stand-in')
            ->setPosition(0);
        $this->em->persist($module);
        $department->attachModule($module);

        $this->em->flush();

        return $department;
    }
}
