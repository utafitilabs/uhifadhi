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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Integration\Nav;

use Uhifadhi\Bundle\ShellBundle\Tests\Integration\ContractTestCase;

/**
 * A TREE ROW'S FURNITURE KEEPS ITS SIZE; THE LABEL IS WHAT GIVES WAY.
 *
 * Every tree row is a flex row of an icon, a label and sometimes a caret. With
 * nothing said about them, all three are items that shrink — so the LABEL's
 * length decided the ICON's size, and a place with a real name drew a smaller
 * glyph than a place with a short one. Two rows of the same rung came out with
 * two different icon sizes, which reads as two kinds of row.
 *
 * IT IS THE SHEET'S TO FIX AND NOT A SOURCE'S. Names arrive from the contract;
 * no length is wrong, and a row cannot be asked to apologise for one. The split
 * is the only one that reads the same at every name length: the furniture is
 * fixed and stated, and the label truncates.
 *
 * WHY IT DOES NOT SHOW IN THE DESIGN. The replica's tree is drawn with short
 * seed names, so the squeeze never gets far enough to see. That is exactly the
 * class of defect a contract over the sheet is for.
 */
final class SidebarRowFurnitureTest extends ContractTestCase
{
    /**
     * The icon is a stated size and refuses to shrink. Stated as well as fixed,
     * because an icon with no size of its own is not small — it is as wide as
     * whatever the row has left, which is how it came to be measured in the
     * first place.
     */
    public function testATreeRowsIconIsStatedAndNeverShrinks(): void
    {
        self::assertMatchesRegularExpression(
            '/\.ntree :is\(a, span\) > svg \{[^}]*flex: none[^}]*width: 1em[^}]*height: 1em/',
            $this->stylesheet(),
            'A tree row\'s icon must be stated and unshrinkable, or the label\'s length sizes it.',
        );
    }

    /** And so does the caret, for the same reason and in the same row. */
    public function testACaretNeverShrinksEither(): void
    {
        self::assertMatchesRegularExpression(
            '/\.nav \.chev \{[^}]*flex: none/',
            $this->stylesheet(),
        );
    }

    /**
     * THE LABEL IS THE ONE THING THAT GIVES WAY, and it does it by truncating
     * rather than by wrapping the row into two lines: a tree is read down its
     * left edge, and a row that is sometimes two rows tall breaks that reading.
     */
    public function testThePlacesNameIsWhatTruncates(): void
    {
        self::assertMatchesRegularExpression(
            '/\.ntree \.nta b \{[^}]*white-space: nowrap[^}]*overflow: hidden[^}]*text-overflow: ellipsis/',
            $this->stylesheet(),
        );
    }
}
