<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the BabelQueue envelope `meta.id` (the canonical per-message identity)
 * through the Messenger pipeline.
 *
 * The serializer attaches it on decode, so consume-side middleware — notably the
 * {@see \BabelQueue\Symfony\Messenger\IdempotencyMiddleware} — can deduplicate a
 * redelivered message on its stable id without reaching back into the raw
 * envelope body. It is a read-only, consume-side artifact: nothing about the
 * frozen wire envelope changes (the id already lives in `meta.id`).
 */
final class BabelMessageIdStamp implements StampInterface
{
    public function __construct(
        public readonly string $messageId,
    ) {
    }
}
