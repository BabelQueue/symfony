<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Messenger;

use BabelQueue\Idempotency\ClaimingDispatch;
use BabelQueue\Idempotency\ClaimingStore;
use BabelQueue\Idempotency\ClaimParkedException;
use BabelQueue\Idempotency\IdempotencyStore;
use BabelQueue\Symfony\Messenger\Stamp\BabelMessageIdStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Throwable;

/**
 * Deduplicates a redelivered BabelQueue message on its canonical `meta.id`, so a
 * handler runs **once** per logical message even under the broker's at-least-once
 * delivery (ADR-0022). The Symfony-idiomatic wrapper around the core
 * {@see \BabelQueue\Idempotency\Idempotent::wrap()} /
 * {@see ClaimingDispatch::wrap()} helpers, adapted to the Messenger stack (which
 * has no {@see \BabelQueue\Contracts\ConsumedMessage} — the id rides on a
 * {@see BabelMessageIdStamp} the serializer attaches on decode).
 *
 * It only acts on the **receive** path (a message coming off a transport, marked
 * by a {@see ReceivedStamp}); a fresh outbound dispatch passes straight through
 * untouched. Register it BEFORE the handler middleware on a bus that consumes a
 * BabelQueue transport:
 *
 *     framework:
 *       messenger:
 *         buses:
 *           messenger.bus.default:
 *             middleware:
 *               - 'babelqueue.messenger.idempotency_middleware'
 *
 * Per inbound delivery, keyed on `meta.id`:
 *
 * - **no usable id** (no stamp / empty) → handle unchanged (fail-open), exactly
 *   like the core helpers.
 * - **already processed** → short-circuit WITHOUT invoking the handler; returning
 *   normally makes the worker ack the delivery so the broker stops redelivering.
 * - **first delivery** → handle once; on a clean return, record the id so a later
 *   redelivery skips. A thrown handler leaves the id UNRECORDED and the exception
 *   propagates, so Messenger's retry / failure transport (and DLQ) still apply and
 *   a later delivery runs the handler again.
 *
 * When the configured store is a {@see ClaimingStore} (a shared, persistent
 * backend), the stronger claim/commit/release lifecycle is used instead: exactly
 * one of N concurrent deliveries of the same id wins the claim and runs; a
 * delivery that loses to an in-flight peer throws {@see ClaimParkedException} so
 * Messenger does NOT ack it — the broker redelivers it later, by when the winner
 * has committed and it will skip. This is still at-least-once, never exactly-once.
 */
final class IdempotencyMiddleware implements MiddlewareInterface
{
    /**
     * @param  int  $ttlSeconds  In-flight claim TTL (the crash backstop), used only
     *                           when {@see $store} is a {@see ClaimingStore}.
     */
    public function __construct(
        private IdempotencyStore $store,
        private int $ttlSeconds = ClaimingDispatch::DEFAULT_TTL,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Only deduplicate messages received off a transport; a fresh outbound
        // dispatch has no stable delivery to dedupe.
        if ($envelope->last(ReceivedStamp::class) === null) {
            return $stack->next()->handle($envelope, $stack);
        }

        $stamp = $envelope->last(BabelMessageIdStamp::class);
        $id = $stamp instanceof BabelMessageIdStamp ? $stamp->messageId : '';

        // No usable id → cannot dedupe; handle unchanged (fail-open).
        if ($id === '') {
            return $stack->next()->handle($envelope, $stack);
        }

        if ($this->store instanceof ClaimingStore) {
            return $this->handleClaiming($this->store, $id, $envelope, $stack);
        }

        return $this->handleSeenSet($this->store, $id, $envelope, $stack);
    }

    /**
     * Post-success "seen-set" dedupe (the base {@see IdempotencyStore} contract).
     */
    private function handleSeenSet(
        IdempotencyStore $store,
        string $id,
        Envelope $envelope,
        StackInterface $stack,
    ): Envelope {
        // Already processed on an earlier delivery: skip the handler and return so
        // the worker acks it and the broker stops redelivering.
        if ($store->seen($id)) {
            return $envelope;
        }

        // First delivery wins. A throw here leaves the id unrecorded → retry/DLQ.
        $result = $stack->next()->handle($envelope, $stack);
        $store->remember($id);

        return $result;
    }

    /**
     * Atomic claim/commit/release (the {@see ClaimingStore} extension): closes the
     * in-flight window the seen-set cannot, for a fleet sharing a persistent store.
     */
    private function handleClaiming(
        ClaimingStore $store,
        string $id,
        Envelope $envelope,
        StackInterface $stack,
    ): Envelope {
        // Already committed on an earlier delivery: skip + ack.
        if ($store->seen($id)) {
            return $envelope;
        }

        // Atomic claim: exactly one concurrent worker wins.
        if (! $store->claim($id, $this->ttlSeconds)) {
            // Lost the race. Either a peer just committed (re-check seen() so we ack
            // a now-committed id) or a peer holds an unexpired in-flight claim, in
            // which case we park: throwing means Messenger does NOT ack, so the
            // broker redelivers later when the winner has committed.
            if ($store->seen($id)) {
                return $envelope;
            }

            throw new ClaimParkedException($id);
        }

        // We own the claim. Commit on success, release on failure.
        try {
            $result = $stack->next()->handle($envelope, $stack);
        } catch (Throwable $e) {
            $store->release($id); // let a redelivery re-run promptly; TTL is the backstop
            throw $e;
        }
        $store->remember($id);

        return $result;
    }
}
