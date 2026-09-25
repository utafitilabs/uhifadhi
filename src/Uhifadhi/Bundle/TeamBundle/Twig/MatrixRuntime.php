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

namespace Uhifadhi\Bundle\TeamBundle\Twig;

use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatLegend;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatLegendEntry;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatTint;
use Uhifadhi\Bundle\TeamBundle\Performance\MatrixPlacing;
use Uhifadhi\Bundle\TeamBundle\Performance\MatrixViewBuilder;
use Uhifadhi\Contracts\Performance\TopicMatrix;

/**
 * THE DP·01 GRAMMAR, drawn from a published matrix.
 *
 * The arithmetic and every word are the builder's, the table and its
 * legend are the atlas's (`atlas_heatmap()`, `atlas_heat_legend()`)
 * ({@see MatrixViewBuilder}); this settles the card around the table and
 * what the caller is allowed to say about it — its title, the line under
 * the title, and who published it. Nothing else: a caller cannot hand in
 * a class, a colour or a column, because then two matrices would differ
 * by who wrote the page rather than by what they measure.
 *
 * @see https://symfony.com/doc/current/templating/twig_extension.html — a lazy-loaded extension's work lives in a RuntimeExtensionInterface class
 * @see vendor/symfony/twig-bundle/DependencyInjection/Compiler/RuntimeLoaderPass.php — the 'twig.runtime' tag config/services.php writes by hand, collected into twig.runtime_loader
 */
final readonly class MatrixRuntime implements RuntimeExtensionInterface
{
    private const string TEMPLATE = '@Team/performance/_matrix.html.twig';

    public function __construct(
        private Environment $twig,
        private MatrixViewBuilder $builder,
    ) {
    }

    /**
     * @param array{
     *     title?: string,
     *     caption?: string,
     *     publisher?: string,
     *     byModule?: bool,
     *     id?: string,
     *     openLabel?: string,
     * } $options the card's own words: what the topic is called, the line
     *            under it, whose topic it is, and what the way into a
     *            department is called on this matrix
     */
    public function renderMatrix(TopicMatrix $matrix, array $options = []): string
    {
        $view = $this->builder->build($matrix);

        return $this->twig->render(self::TEMPLATE, [
            'view' => $view,
            'title' => $options['title'] ?? '',
            'caption' => $options['caption'] ?? $matrix->caption,
            'publisher' => $options['publisher'] ?? '',
            'byModule' => $options['byModule'] ?? false,
            'id' => $options['id'] ?? '',
            'openLabel' => $options['openLabel'] ?? 'Open',
            'legend' => self::legend(),
        ]);
    }

    /**
     * THE LEGEND IS PART OF THE MATRIX: a shade nobody explained is a verdict
     * the reader invents. It says what a tint is AND what it is not, because
     * the second is the half that gets misread — and it says the rule, which
     * has exactly one home.
     */
    private static function legend(): HeatLegend
    {
        return new HeatLegend(
            [
                new HeatLegendEntry(HeatTint::Leads, 'leads the column'),
                new HeatLegendEntry(HeatTint::Mid, 'mid'),
                new HeatLegendEntry(HeatTint::Trails, 'trails the column'),
                new HeatLegendEntry(HeatTint::None, "no figure yet \u{2014} not a zero"),
            ],
            \sprintf("A placing inside one column and one band \u{2014} never a verdict on a department, and not drawn where fewer than %d in the band have a figure.", MatrixPlacing::FEWEST),
        );
    }
}
