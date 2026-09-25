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

use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\MercureBundle\MercureBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\UX\Icons\UXIconsBundle;
use Symfony\UX\Map\UXMapBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Uhifadhi\Bundle\AreaBundle\AreaBundle;
use Uhifadhi\Bundle\AtlasBundle\AtlasBundle;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\TeamBundle\TeamBundle;
use UtafitiLabs\PostGISBundle\UtafitiLabsPostGISBundle;

/*
 * What the throwaway application has installed. It grows one line per core
 * bundle as each lands, which is exactly what an installation's own
 * config/bundles.php does — Flex writes those lines from the core's recipe.
 */
return [
    FrameworkBundle::class => ['all' => true],
    DoctrineBundle::class => ['all' => true],
    DoctrineMigrationsBundle::class => ['all' => true],
    UtafitiLabsPostGISBundle::class => ['all' => true],
    TwigBundle::class => ['all' => true],
    UXIconsBundle::class => ['all' => true],
    StimulusBundle::class => ['all' => true],
    UXMapBundle::class => ['all' => true],
    SecurityBundle::class => ['all' => true],
    ApiPlatformBundle::class => ['all' => true],
    MercureBundle::class => ['all' => true],
    RegistryBundle::class => ['all' => true],
    ShellBundle::class => ['all' => true],
    AtlasBundle::class => ['all' => true],
    TeamBundle::class => ['all' => true],
    AreaBundle::class => ['all' => true],
];
