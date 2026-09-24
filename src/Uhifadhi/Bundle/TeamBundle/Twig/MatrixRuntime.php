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
use Uhifadhi\Bundle\TeamBundle\Performance\MatrixPlacing;
use Uhifadhi\Bundle\TeamBundle\Performance\MatrixViewBuilder;
use Uhifadhi\Contracts\Performance\TopicMatrix;

/**
 * THE DP·01 GRAMMAR, drawn from a published matrix.
 *
 * The arithmetic and every word are the builder's
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
            // The legend says the rule, and the rule has exactly one home.
            'fewest' => MatrixPlacing::FEWEST,
        ]);
    }
}
