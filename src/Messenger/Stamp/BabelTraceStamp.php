<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the BabelQueue cross-service `trace_id` through the Messenger pipeline.
 *
 * The serializer attaches it on decode (so handlers can read the inbound trace
 * id) and honours it on encode (so a downstream message can continue the same
 * trace instead of starting a new one).
 */
final class BabelTraceStamp implements StampInterface
{
    public function __construct(
        public readonly string $traceId,
    ) {
    }
}
