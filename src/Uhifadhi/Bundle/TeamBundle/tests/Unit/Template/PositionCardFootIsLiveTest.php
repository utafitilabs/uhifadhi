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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * THE POSITION CARD'S FOOT SAYS WHAT THE FORM NOW HOLDS, the way the grants
 * card's does: "no changes" only while nothing moved, and the departments
 * counted from the boxes as they stand — not a sentence drawn once at load
 * that went on saying "no changes" after three boxes were ticked.
 */
#[CoversNothing]
final class PositionCardFootIsLiveTest extends TestCase
{
    private const string CONTROLLER = 'uhifadhi--team-bundle--position-card';

    public function testTheCardsFormAttachesTheController(): void
    {
        self::assertMatchesRegularExpression(
            '/<form method="post" action="\{\{ path\(\'team_member_position\'[^>]*data-controller="'.self::CONTROLLER.'"/',
            self::template(),
        );
    }

    public function testTheFootIsTheControllersPreview(): void
    {
        self::assertStringContainsString('<span class="chg" data-'.self::CONTROLLER.'-target="preview">', self::template());
    }

    /** Each position carries how many people it would reach, so a new choice can say so. */
    public function testEveryChoiceCarriesItsReach(): void
    {
        self::assertStringContainsString('data-reach="{{', self::template());
    }

    public function testTheControllerIsDeclaredAndShipped(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/assets/package.json'), true);
        self::assertIsArray($manifest);
        $symfony = $manifest['symfony'] ?? null;
        self::assertIsArray($symfony);
        $controllers = $symfony['controllers'] ?? null;
        self::assertIsArray($controllers);
        $card = $controllers['position-card'] ?? null;
        self::assertIsArray($card);
        self::assertSame('controllers/position_card_controller.js', $card['main'] ?? null);
        self::assertFileExists(\dirname(__DIR__, 3).'/assets/controllers/position_card_controller.js');
    }

    private static function template(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/templates/team/member_configure.html.twig');
    }
}
