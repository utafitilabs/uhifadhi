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

namespace Uhifadhi\Bundle\AreaBundle\Controller;

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Bundle\AreaBundle\Service\LiveSheet;

/**
 * WHO A LIVE MARK IS, for the plate that drew it (ruled 30 Sep, #16 C). One
 * address for every plate - the area overview, the organization dashboard and
 * the roster's Live tab - keyed by the person, because a mark at organization
 * scope belongs to whichever area the watch is in.
 *
 * GATED BY THE READING, NOT A PAIR ON THE ROUTE: {@see LiveSheet} answers only
 * for a mark the viewer's plate would draw - the person's area readable, the
 * person within their live sight - and anything else is not found, as a
 * refusal is everywhere (#33).
 */
final readonly class LiveSheetController
{
    public const string ROUTE = 'area_live_sheet';

    private const string SENTINEL = '00000000-0000-0000-0000-000000000000';

    /**
     * THE ADDRESS A PLATE ASKS, `{id}` standing for the person - what
     * `AtlasMap::livePositions()` is handed. Null where the route is not
     * mounted, and then the marks simply open nothing.
     */
    public static function addressTemplate(UrlGeneratorInterface $urls): ?string
    {
        try {
            return str_replace(self::SENTINEL, '{id}', $urls->generate(self::ROUTE, ['person' => self::SENTINEL]));
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    public function __construct(
        private LiveSheet $sheets,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/live/{person}', name: self::ROUTE, requirements: ['person' => '[0-9a-fA-F]{8}-?[0-9a-fA-F]{4}-?[0-9a-fA-F]{4}-?[0-9a-fA-F]{4}-?[0-9a-fA-F]{12}'], methods: ['GET'])]
    public function show(string $person): JsonResponse
    {
        $sheet = $this->sheets->for($person, $this->clock->now())
            ?? throw new NotFoundHttpException('Nobody you can see is live at that address.');

        return new JsonResponse($sheet->toArray(), headers: ['Cache-Control' => 'no-store']);
    }
}
