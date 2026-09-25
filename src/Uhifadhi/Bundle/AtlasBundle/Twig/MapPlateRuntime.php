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

namespace Uhifadhi\Bundle\AtlasBundle\Twig;

use Symfony\UX\Map\Renderer\RendererInterface;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Bundle\AtlasBundle\Model\LegendItem;

/**
 * `render_map()` — the whole of what a module writes to have a map.
 *
 * IT RENDERS A PLATE, NOT A MAP ELEMENT. UX Map renders the element its bridge
 * controller mounts on; a plate is that element plus everything around it that
 * must not be a module's decision: the wrapper that goes fullscreen, the filter
 * row above the map, the legend floating over it. Those three have to be laid
 * out together — the wrapper is a flex column so the map grows into fullscreen
 * — so they are emitted together, here, once.
 *
 * A RUNTIME rather than work done in the extension, because rendering needs Twig
 * itself and the configured UX Map renderer, and a page that draws no map should
 * pay for neither.
 *
 * @see vendor/symfony/ux-map/src/Twig/MapRuntime.php
 */
final class MapPlateRuntime implements RuntimeExtensionInterface
{
    /**
     * The plate's Stimulus identifier — the asset package name and the
     * controller name, as StimulusBundle derives it from a bundle's
     * assets/package.json.
     *
     * @see https://symfony.com/bundles/StimulusBundle/current/index.html
     */
    public const string CONTROLLER = 'uhifadhi--atlas-bundle--map-plate';

    /**
     * WHAT MARKS A PLATE IN A PAGE, so the plate can find ITSELF in another copy
     * of that page.
     *
     * A filter change made in fullscreen fetches the same address with the new
     * query and swaps the plate's own subtrees out of the answer. To do that it
     * has to recognise which element of the fetched document is this plate, and
     * a class would not do: `.map-plate` is a style hook a host may restyle or
     * reuse, and losing it would break the swap silently. This attribute is the
     * plate's identity and nothing else's.
     *
     * It carries no value. A page may hold several plates, and which one is
     * which is their ORDER in the document — the same in the answer as on the
     * page, because it is the same page.
     */
    public const string PLATE_HOOK = 'data-atlas-plate';

    /** The plate template, rendered through the namespace the bundle prepends. */
    private const string TEMPLATE = '@Atlas/plate.html.twig';

    /**
     * The classes the map element carries whatever a caller asks for: the
     * imagery frame's canvas, and the stacking context that keeps a zoom pill
     * from floating over a dialog.
     */
    private const string CANVAS_CLASSES = 'map-canvas map-chrome-host';

    /**
     * WHAT MARKS AN ATTRIBUTE AS THE PLATE'S RATHER THAN THE MAP ELEMENT'S.
     *
     * A plate's height is one custom property (`--map-plate-height`), and a
     * custom property has to land on the PLATE: set on the canvas inside it, it
     * would size nothing. Rather than a second parameter meaning "attributes,
     * but for the wrapper", anything written as a custom property is understood
     * to be about the plate and is lifted onto it; everything else stays the map
     * element's, as it always was.
     */
    public const string CUSTOM_PROPERTY_PREFIX = '--';

    public function __construct(
        private readonly Environment $twig,
        private readonly RendererInterface $renderer,
    ) {
    }

    /**
     * @param array<string, bool|string> $attributes attributes for the MAP element — an aria-label,
     *                                               a role, a module's own data attribute; a key written
     *                                               as a custom property (`--map-plate-height`) sizes the
     *                                               PLATE instead
     * @param string|null                $filters    markup for the row above the map; already-escaped
     *                                               HTML, as a `{% set %}` block or a rendered include
     */
    public function renderMap(AtlasMap $map, array $attributes = [], ?string $filters = null): string
    {
        $plateStyle = self::plateStyle($attributes);
        foreach (array_keys($plateStyle) as $property) {
            unset($attributes[$property]);
        }

        // A PLATE MAY DECLINE ITS LEGEND — a thumbnail on a record beside the
        // full plate it stands for, where the key would be taller than the
        // map. The layers keep their legend; this plate just does not draw it.
        $legend = $attributes['legend'] ?? true;
        unset($attributes['legend']);

        $class = $attributes['class'] ?? null;
        $attributes['class'] = \is_string($class) && '' !== $class
            ? self::CANVAS_CLASSES.' '.$class
            : self::CANVAS_CLASSES;

        return $this->twig->render(self::TEMPLATE, [
            'controller' => self::CONTROLLER,
            'hook' => self::PLATE_HOOK,
            'element' => $this->renderer->renderMap($map->toUxMap(), $attributes),
            'groups' => false === $legend ? [] : self::group($map->legend()),
            'filters' => $filters,
            'pick' => $map->pick(),
            'plateStyle' => implode(';', array_map(
                static fn (string $property, string $value): string => $property.':'.$value,
                array_keys($plateStyle),
                array_values($plateStyle),
            )),
        ]);
    }

    /**
     * The custom properties among the attributes — the plate's, in the order
     * they were written.
     *
     * @param array<string, bool|string> $attributes
     *
     * @return array<string, string>
     */
    private static function plateStyle(array $attributes): array
    {
        $style = [];
        foreach ($attributes as $property => $value) {
            if (str_starts_with($property, self::CUSTOM_PROPERTY_PREFIX) && \is_string($value)) {
                $style[$property] = $value;
            }
        }

        return $style;
    }

    /**
     * The legend's rows under their headings, each heading in the order its
     * first row appeared — so a contributor's rows read as that contributor's
     * and the order is the one the map stated.
     *
     * @param list<LegendItem> $items
     *
     * @return list<array{label: string|null, items: list<LegendItem>}>
     */
    private static function group(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $key = $item->group ?? '';
            $groups[$key] ??= ['label' => $item->group, 'items' => []];
            $groups[$key]['items'][] = $item;
        }

        return array_values($groups);
    }
}
