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

namespace Uhifadhi\Bundle\AreaBundle\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Bundle\AreaBundle\Api\DutyApiException;
use Uhifadhi\Bundle\AreaBundle\Api\DutyResponse;

/**
 * WHAT THE TWO DUTY READS HAVE IN COMMON: they answer with a document
 * they built themselves, and they turn a refusal into the contract's
 * own error shape.
 *
 * THE MIRROR OF {@see DutyProcessor}, and deliberately its twin rather
 * than something cleverer: a reader and a writer on the same surface
 * that failed differently would be two contracts wearing one name.
 *
 * @implements ProviderInterface<Response>
 *
 * @see https://api-platform.com/docs/core/state-providers/ — a provider implements ProviderInterface and an operation names it in `provider:`
 * @see vendor/api-platform/core/src/State/ProviderInterface.php — the one method these subclasses answer
 */
abstract class DutyProvider implements ProviderInterface
{
    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    final public function provide(Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        try {
            return new JsonResponse($this->read($uriVariables));
        } catch (DutyApiException $refusal) {
            return DutyResponse::refusal($refusal);
        }
    }

    /**
     * @param array<string, mixed> $uriVariables
     *
     * @return array<string, mixed>
     *
     * @throws DutyApiException
     */
    abstract protected function read(array $uriVariables): array;

    /**
     * The uuid a URI names, as a string.
     *
     * @param array<string, mixed> $uriVariables
     */
    final protected static function uri(array $uriVariables, string $key): string
    {
        $value = $uriVariables[$key] ?? null;

        return \is_string($value) ? $value : '';
    }
}
