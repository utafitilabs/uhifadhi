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

namespace Uhifadhi\Bundle\RegistryBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Bundle\RegistryBundle\Repository\FigureFactRepository;

/**
 * ONE FIGURE, FOR ONE SUBJECT, IN ONE PERIOD, as the worker last computed it
 * — a row of the facts ledger.
 *
 * THE ROW IS ITS KEY. Subject kind, subject, figure and period identify a
 * fact, and the next run of the same period replaces the row rather than
 * adding one; so the four columns are the primary key and there is no
 * surrogate id to keep unique beside them.
 *
 * THE VALUE IS A DOUBLE, NULLABLE. A figure is a number a page prints and a
 * quarter adds up: the providers hand floats (the KPI contract's `?float`),
 * so a fixed-scale NUMERIC would store a rounding of a float and hand PHP a
 * string back, and JSONB would allow a structure no page reads and no SUM
 * can add. Structured facts — a covered geometry, a list — are a module's
 * own table. NULL is UNKNOWN, a measured nothing is 0.
 *
 * WRITTEN BY SQL, NOT BY THE UNIT OF WORK: {@see FigureFactRepository::upsert()}
 * is one `INSERT … ON CONFLICT DO UPDATE` per figure. The mapping exists so
 * the schema this class states is the schema the shipped migration builds,
 * which the core's migrations suite compares.
 */
#[ORM\Entity(repositoryClass: FigureFactRepository::class, readOnly: true)]
#[ORM\Table(name: 'figure_fact')]
#[ORM\Index(name: 'idx_figure_fact_figure_period', columns: ['figure_key', 'period_key'])]
class FigureFact
{
    #[ORM\Id]
    #[ORM\Column(name: 'subject_kind', length: 64)]
    private string $subjectKind = '';

    #[ORM\Id]
    #[ORM\Column(name: 'subject_uuid', type: Types::GUID)]
    private string $subjectUuid = '';

    #[ORM\Id]
    #[ORM\Column(name: 'figure_key', length: 128)]
    private string $figureKey = '';

    /** `2026-09`, `2026-Q3`, `2026`. */
    #[ORM\Id]
    #[ORM\Column(name: 'period_key', length: 7)]
    private string $periodKey = '';

    #[ORM\Column(name: 'value', type: Types::FLOAT, nullable: true)]
    private ?float $value = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(name: 'computed_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $computedAt;

    public function __construct()
    {
        $this->computedAt = new \DateTimeImmutable();
    }

    public function getSubjectKind(): string
    {
        return $this->subjectKind;
    }

    public function getSubjectUuid(): string
    {
        return $this->subjectUuid;
    }

    public function getFigureKey(): string
    {
        return $this->figureKey;
    }

    public function getPeriodKey(): string
    {
        return $this->periodKey;
    }

    public function getValue(): ?float
    {
        return $this->value;
    }

    public function getComputedAt(): \DateTimeImmutable
    {
        return $this->computedAt;
    }
}
