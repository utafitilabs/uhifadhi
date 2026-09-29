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

namespace Uhifadhi\Bundle\AreaBundle\Devkit;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;
use Uhifadhi\Contracts\Entity\UserInterface;

/**
 * THE POSTS AND THE PEOPLE WORKING OUT OF THEM — each seeded reserve's own
 * posts, and the roster spread across them with somebody in charge at each.
 *
 * ONE SLICE, NOT TWO. A station and its staffing are separable in the database
 * and inseparable on the screen: every page that draws a post draws who stands
 * there, and an empty staffing list means something — see below — only when the
 * posts around it are staffed. Seeding them together is what makes the emptiness
 * legible.
 *
 * IT SEEDS THE THREE STATES THE SCREENS HAVE TO DRAW, deliberately:
 *
 *   · a post the zoning scheme does not reach, so its zone is blank because the
 *     ground is, not because the derivation failed;
 *   · a post nobody works out of, which is a staffing gap rather than an error,
 *     and every post past the end of the roster, which is the same thing said
 *     by arithmetic;
 *   · everywhere else, exactly one leader — the invariant
 *     {@see PostingService::appointLeader()} holds, seen from the outside.
 *
 * THE ZONE IS NEVER SET HERE. A station's zone is derived from where it stands,
 * and a seeder that assigned one would be asserting the answer instead of
 * producing it — which is precisely the bug such a seeder would hide. This runs
 * after the zones for the same reason a real post is placed after the scheme is
 * imported.
 *
 * THE ROSTER IS READ THROUGH THE CONTRACT. This bundle knows about people only
 * as {@see UserInterface}, the association a posting is mapped at; who those
 * people are, and which module put them there, is not its business.
 *
 * IT SEEDS ONCE, PER AREA. An area that already has posts is left as it is.
 */
final readonly class StationContentProvider implements ContentProviderInterface
{
    public function __construct(
        private AreaOfInterestRepository $register,
        private StationRepository $posts,
        private StationService $stations,
        private PostingService $postings,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function key(): string
    {
        return 'station';
    }

    public function label(): string
    {
        return 'Stations';
    }

    public function description(): string
    {
        return 'Posts standing in the seeded reserves, and the people working out of them.';
    }

    public function dependsOn(): array
    {
        return ['area', 'team', 'zone'];
    }

    public function load(): void
    {
        /*
         * THE ROSTER IS HANDED OUT ONCE. Somebody stands at ONE post (ruled),
         * so this walks the roster forward across every area and every post
         * and never round it: a seed that put the same ranger at two gates
         * was describing a state the product refuses, and the seeder was the
         * first thing to hit that refusal.
         *
         * WHEN IT RUNS OUT, THE REST OF THE POSTS STAND EMPTY — which is a
         * state the screens already draw, and an honest one: an installation
         * with nine posts and four people has five empty posts.
         */
        $roster = $this->roster();
        $next = 0;

        foreach (SeedArea::all() as $seed) {
            $area = $this->register->findOneBy(['name' => $seed->name]);

            if (null === $area || $this->posts->countByArea($area) > 0) {
                continue;
            }

            foreach ($seed->stations() as $post) {
                [$lon, $lat] = $seed->pointOf($post);

                $this->stations->add(
                    $area,
                    $post->name,
                    $lon,
                    $lat,
                    $post->code,
                    elevationM: $post->elevationM,
                    locality: $post->locality,
                    catchmentM: $post->catchmentM,
                );
                $station = $this->posts->findOneBy(['area' => $area, 'name' => $post->name]);
                if (null === $station) {
                    continue;
                }

                // HOW MANY WORK HERE IS THE POST'S OWN NUMBER, and two of
                // them are nought: an empty post is described by the table
                // rather than by an index this file has to keep in step
                // with it.
                $this->staff($station, $post->posted, $roster, $next);
            }
        }
    }

    /**
     * SOMEBODY IN CHARGE AND AS MANY BEHIND THEM AS THE POST ASKS FOR —
     * taken from wherever the roster has got to and never from the
     * beginning again.
     *
     * A PERSON IS SPENT WHEN THEY ARE POSTED. One posting a person (ruled),
     * so `$next` only ever moves forward; a post reached after the last
     * person stands empty rather than borrowing somebody from an earlier
     * gate.
     *
     * @param int                 $wanted how many work out of this post; nought is a post nobody does
     * @param list<UserInterface> $roster
     * @param int                 $next   how far through the roster the seeding has got
     */
    private function staff(Station $station, int $wanted, array $roster, int &$next): void
    {
        if ($wanted < 1 || !isset($roster[$next])) {
            return;
        }

        $leader = $this->postings->post($station, $roster[$next], PostingSource::WrittenHere);
        ++$next;
        $this->postings->appointLeader($leader);

        for ($n = 1; $n < $wanted; ++$n) {
            if (!isset($roster[$next])) {
                return;
            }

            $this->postings->post($station, $roster[$next], PostingSource::WrittenHere);
            ++$next;
        }
    }

    /**
     * EVERYBODY A POSTING COULD NAME. An installation seeded without a roster
     * still gets its posts; they simply stand empty until somebody is hired.
     *
     * @return list<UserInterface>
     */
    private function roster(): array
    {
        return $this->entityManager->getRepository(UserInterface::class)->findBy([], ['id' => 'ASC']);
    }
}
