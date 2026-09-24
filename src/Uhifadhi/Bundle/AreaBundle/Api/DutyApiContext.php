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

namespace Uhifadhi\Bundle\AreaBundle\Api;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\AreaBundle\Access\AreaConcerns;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Contracts\Entity\UserInterface;

/**
 * WHO IS ASKING, ABOUT WHICH AREA, AND WHAT THEY SENT.
 *
 * THE THREE QUESTIONS EVERY DUTY ENDPOINT STARTS WITH, answered once.
 * The patrol module's own context does the same job for its endpoints,
 * and the shape is deliberately the same: a handset that learnt one of
 * these APIs has learnt both.
 *
 * THE PERSON IS THE TOKEN'S, NEVER THE BODY'S. `personUuid` is on the
 * wire because the app's own record carries it, and it is read for
 * nothing: a phone that could name somebody else would be a phone that
 * could check somebody else in.
 */
final readonly class DutyApiContext
{
    /**
     * THE PAIR THE GATE ASKS FOR. Reporting a day is the Duty concern with the
     * Record verb, spelt from the declaration itself so that what the bundle
     * says it enforces and what it actually asks cannot drift apart.
     */
    private const string CHECK_IN = AreaConcerns::DUTY.'.'.Verb::Record->value;

    public function __construct(
        private RequestStack $requestStack,
        private TokenStorageInterface $tokens,
        private AuthorizationCheckerInterface $authorization,
        private AreaOfInterestRepository $areas,
    ) {
    }

    /**
     * THE ACCOUNT THE TOKEN NAMES, and whether it may report a day ON THIS
     * GROUND.
     *
     * THE AREA IS NOT OPTIONAL, and that is the whole of it: duty is a
     * per-area concern, so asking the pair without the area asks the
     * placement question with nothing to answer it about — and a handset
     * whose ranger is posted at one area could then write a day into
     * another. Every endpoint here names its area in the URI, so there is
     * never a moment when it is not known.
     *
     * @throws DutyApiException
     */
    public function requireRanger(AreaOfInterest $area): UserInterface
    {
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            throw DutyApiException::unauthorized();
        }

        if (!$this->authorization->isGranted(self::CHECK_IN, $area)) {
            throw DutyApiException::forbidden();
        }

        return $user;
    }

    /**
     * The area a URI names.
     *
     * @throws DutyApiException
     */
    public function area(string $uuid): AreaOfInterest
    {
        return $this->areas->findOneBy(['uuid' => $uuid])
            ?? throw DutyApiException::unknownArea($uuid);
    }

    /**
     * The decoded JSON body.
     *
     * @return array<string, mixed>
     *
     * @throws DutyApiException
     */
    public function body(): array
    {
        $content = $this->request()->getContent();
        if ('' === $content) {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw DutyApiException::invalidPayload('The request body is not valid JSON.', ['reason' => $exception->getMessage()]);
        }

        if (!\is_array($decoded)) {
            throw DutyApiException::invalidPayload('The request body must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function query(string $key): ?string
    {
        $value = $this->request()->query->get($key);

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /** @throws DutyApiException */
    private function request(): Request
    {
        return $this->requestStack->getCurrentRequest()
            ?? throw DutyApiException::invalidPayload('There is no request to read.');
    }
}
