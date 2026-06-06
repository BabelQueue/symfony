<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Contracts;

use BabelQueue\Contracts\PolyglotJob;

/**
 * A Symfony message that travels as the BabelQueue canonical envelope.
 *
 * It composes the framework-agnostic core {@see PolyglotJob} (the URN via
 * getBabelUrn() and the JSON payload via toPayload()) with a consume-side
 * factory, so the {@see \BabelQueue\Symfony\Messenger\BabelQueueSerializer} can
 * rebuild the message object from a decoded "data" block.
 *
 * Optionally implement {@see \BabelQueue\Contracts\HasTraceId} to start a message
 * inside an existing distributed trace.
 */
interface PolyglotMessage extends PolyglotJob
{
    /**
     * Rebuild the message from the decoded envelope "data" block.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromBabelPayload(array $data): static;
}
