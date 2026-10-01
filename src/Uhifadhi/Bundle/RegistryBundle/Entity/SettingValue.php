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

use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Bundle\RegistryBundle\Repository\SettingValueRepository;
use Uhifadhi\Contracts\Settings\SettingDepth;

/**
 * ONE VALUE SET IN SETTINGS: the organization's for a setting, or a custom one
 * for an area or a department. A place without a row follows the level above;
 * the definition's default stands when the organization has none. Who set it
 * and when travel with it, because only Super Admins and Admins write here and
 * a reader of the page should be able to ask.
 *
 * The place is the area's or the department's uuid, held as a uuid and not as
 * a relation: the registry names no sibling bundle, and a value whose place is
 * deleted is simply never read again.
 */
#[ORM\Entity(repositoryClass: SettingValueRepository::class)]
#[ORM\Table(name: 'setting_value')]
#[ORM\UniqueConstraint(name: 'uniq_setting_value_organization', columns: ['setting_key'], options: ['where' => '(place_uuid IS NULL)'])]
#[ORM\UniqueConstraint(name: 'uniq_setting_value_place', columns: ['setting_key', 'level', 'place_uuid'], options: ['where' => '(place_uuid IS NOT NULL)'])]
class SettingValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(name: 'setting_key', length: 120)]
    private string $key;

    #[ORM\Column(name: 'level', length: 16, enumType: SettingDepth::class)]
    private SettingDepth $level;

    #[ORM\Column(name: 'place_uuid', type: 'guid', nullable: true)]
    private ?string $placeUuid;

    #[ORM\Column(name: 'value', type: 'json')]
    private int|bool|string $value;

    #[ORM\Column(name: 'set_by', length: 180)]
    private string $setBy;

    #[ORM\Column(name: 'set_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $setAt;

    public function __construct(string $key, SettingDepth $level, ?string $placeUuid, int|bool|string $value, string $setBy, \DateTimeImmutable $setAt)
    {
        $this->key = $key;
        $this->level = $level;
        $this->placeUuid = $placeUuid;
        $this->value = $value;
        $this->setBy = $setBy;
        $this->setAt = $setAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLevel(): SettingDepth
    {
        return $this->level;
    }

    public function getPlaceUuid(): ?string
    {
        return $this->placeUuid;
    }

    public function getValue(): int|bool|string
    {
        return $this->value;
    }

    public function getSetBy(): string
    {
        return $this->setBy;
    }

    public function getSetAt(): \DateTimeImmutable
    {
        return $this->setAt;
    }

    public function change(int|bool|string $value, string $setBy, \DateTimeImmutable $setAt): void
    {
        $this->value = $value;
        $this->setBy = $setBy;
        $this->setAt = $setAt;
    }
}
