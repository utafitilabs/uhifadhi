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
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Bundle\AreaBundle\Api\DutyApiException;
use Uhifadhi\Bundle\AreaBundle\Api\DutyResponse;

/**
 * WHAT EVERY DUTY ENDPOINT HAS IN COMMON: it answers with a response it
 * built itself, and it turns a refusal into the contract's own error
 * document.
 *
 * A RESPONSE IS THE SUPPORTED WAY TO MEAN EXACTLY THIS. API Platform
 * passes one straight through, which is what lets these endpoints
 * promise a released client literal key names and a literal status —
 * including 201-on-create against 200-on-repeat, which no serializer
 * configuration could express.
 *
 * REFUSALS ARE CAUGHT HERE rather than left to a host's exception
 * listener, so the bundle's endpoints answer correctly on their own: a
 * surface that can only report failure through its host cannot be
 * tested without one.
 *
 * @implements ProcessorInterface<mixed, Response>
 *
 * @see https://api-platform.com/docs/core/state-processors/ — a processor implements ProcessorInterface and an operation names it in `processor:`
 * @see vendor/api-platform/core/src/State/ProcessorInterface.php — the one method these subclasses answer
 */
abstract class DutyProcessor implements ProcessorInterface
{
    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    final public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        try {
            return $this->handle($uriVariables);
        } catch (DutyApiException $refusal) {
            return DutyResponse::refusal($refusal);
        }
    }

    /**
     * @param array<string, mixed> $uriVariables
     *
     * @throws DutyApiException
     */
    abstract protected function handle(array $uriVariables): Response;

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
