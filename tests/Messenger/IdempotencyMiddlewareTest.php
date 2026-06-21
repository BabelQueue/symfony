<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Tests\Messenger;

use BabelQueue\Idempotency\ClaimingStore;
use BabelQueue\Idempotency\ClaimParkedException;
use BabelQueue\Idempotency\IdempotencyStore;
use BabelQueue\Idempotency\InMemoryStore;
use BabelQueue\Symfony\Messenger\IdempotencyMiddleware;
use BabelQueue\Symfony\Messenger\Stamp\BabelMessageIdStamp;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Drives the middleware through a real in-memory bus (no broker): a message
 * received off a transport is deduplicated on its meta.id, while fresh outbound
 * dispatches and id-less deliveries pass straight through.
 */
final class IdempotencyMiddlewareTest extends TestCase
{
    public function test_a_duplicate_id_is_not_re_handled(): void
    {
        $store = new InMemoryStore();
        $handled = new HandlerCounter();
        $bus = $this->bus($store, $handled);

        $first = $this->inbound('msg-1');
        $duplicate = $this->inbound('msg-1');

        $bus->dispatch($first);
        $bus->dispatch($duplicate);

        // Same meta.id delivered twice => handler runs exactly once.
        $this->assertSame(1, $handled->count);
        $this->assertTrue($store->seen('msg-1'));
    }

    public function test_distinct_ids_are_each_handled_once(): void
    {
        $store = new InMemoryStore();
        $handled = new HandlerCounter();
        $bus = $this->bus($store, $handled);

        $bus->dispatch($this->inbound('msg-1'));
        $bus->dispatch($this->inbound('msg-2'));

        $this->assertSame(2, $handled->count);
    }

    public function test_a_thrown_handler_leaves_the_id_unrecorded_so_a_redelivery_re_runs(): void
    {
        $store = new InMemoryStore();
        $handled = new HandlerCounter(throwOnce: true);
        $bus = $this->bus($store, $handled);

        // First delivery: handler throws => id is NOT remembered, exception propagates
        // (Messenger wraps the handler exception in a HandlerFailedException).
        try {
            $bus->dispatch($this->inbound('msg-1'));
            $this->fail('expected the handler to throw on the first delivery');
        } catch (HandlerFailedException $e) {
            $this->assertSame('boom', $e->getPrevious()?->getMessage());
        }
        $this->assertFalse($store->seen('msg-1'));

        // Redelivery: handler runs again (now succeeds) and commits.
        $bus->dispatch($this->inbound('msg-1'));
        $this->assertSame(2, $handled->count);
        $this->assertTrue($store->seen('msg-1'));
    }

    public function test_an_id_less_delivery_is_handled_unchanged(): void
    {
        $store = new RecordingStore();
        $handled = new HandlerCounter();
        $bus = $this->bus($store, $handled);

        // Received but no BabelMessageIdStamp => fail-open, handle, never touch the store.
        $bus->dispatch(new Envelope(new IdempotentStub(), [new ReceivedStamp('async')]));

        $this->assertSame(1, $handled->count);
        $this->assertSame([], $store->calls);
    }

    public function test_a_fresh_outbound_dispatch_bypasses_dedupe(): void
    {
        $store = new RecordingStore();
        $handled = new HandlerCounter();
        $bus = $this->bus($store, $handled);

        // No ReceivedStamp (a fresh dispatch, not a redelivery): handled, store untouched
        // even though it carries a message-id stamp.
        $bus->dispatch(new Envelope(new IdempotentStub(), [new BabelMessageIdStamp('msg-1')]));

        $this->assertSame(1, $handled->count);
        $this->assertSame([], $store->calls);
    }

    public function test_claiming_store_runs_the_winner_and_commits(): void
    {
        $store = new FakeClaimingStore();
        $handled = new HandlerCounter();
        $bus = $this->bus($store, $handled);

        $bus->dispatch($this->inbound('msg-1'));

        $this->assertSame(1, $handled->count);
        $this->assertContains('claim:msg-1', $store->calls);
        $this->assertContains('remember:msg-1', $store->calls);
        $this->assertTrue($store->seen('msg-1'));
    }

    public function test_claiming_store_parks_a_delivery_that_loses_an_in_flight_claim(): void
    {
        // A peer already holds an unexpired in-flight claim and has not committed.
        $store = new FakeClaimingStore(claimGranted: false);
        $handled = new HandlerCounter();
        $bus = $this->bus($store, $handled);

        $this->expectException(ClaimParkedException::class);

        try {
            $bus->dispatch($this->inbound('msg-1'));
        } finally {
            // Parked => handler never ran (the broker will redeliver later).
            $this->assertSame(0, $handled->count);
        }
    }

    public function test_claiming_store_acks_an_id_committed_by_a_peer_after_losing_the_claim(): void
    {
        // First seen() is false (so we attempt the claim), the claim is refused, and the
        // re-check seen() now shows a peer committed it in the meantime => ack, do not park.
        $store = new FakeClaimingStore(claimGranted: false, commitOnRefusedClaim: true);
        $handled = new HandlerCounter();
        $bus = $this->bus($store, $handled);

        $bus->dispatch($this->inbound('msg-1'));

        // No park, no re-handle: a now-committed duplicate is simply acked.
        $this->assertSame(0, $handled->count);
        $this->assertContains('claim:msg-1', $store->calls);
    }

    public function test_claiming_store_releases_the_claim_when_the_handler_throws(): void
    {
        $store = new FakeClaimingStore();
        $handled = new HandlerCounter(throwOnce: true);
        $bus = $this->bus($store, $handled);

        try {
            $bus->dispatch($this->inbound('msg-1'));
            $this->fail('expected the handler to throw');
        } catch (HandlerFailedException $e) {
            $this->assertSame('boom', $e->getPrevious()?->getMessage());
        }

        $this->assertContains('release:msg-1', $store->calls);
        $this->assertNotContains('remember:msg-1', $store->calls);
    }

    private function inbound(string $id): Envelope
    {
        return new Envelope(new IdempotentStub(), [
            new ReceivedStamp('async'),
            new BabelMessageIdStamp($id),
        ]);
    }

    private function bus(IdempotencyStore $store, HandlerCounter $handled): MessageBus
    {
        $handlers = new HandlersLocator([
            IdempotentStub::class => [static function (IdempotentStub $message) use ($handled): void {
                $handled->handle();
            }],
        ]);

        return new MessageBus([
            new IdempotencyMiddleware($store),
            new HandleMessageMiddleware($handlers),
        ]);
    }
}

/** Counts handler invocations; optionally throws on the first call only. */
final class HandlerCounter
{
    public int $count = 0;

    public function __construct(private bool $throwOnce = false)
    {
    }

    public function handle(): void
    {
        $this->count++;

        if ($this->throwOnce) {
            $this->throwOnce = false;

            throw new RuntimeException('boom');
        }
    }
}

/** An IdempotencyStore that records every call, to assert the store is (not) touched. */
final class RecordingStore implements IdempotencyStore
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, true> */
    private array $seen = [];

    public function seen(string $messageId): bool
    {
        $this->calls[] = "seen:{$messageId}";

        return isset($this->seen[$messageId]);
    }

    public function remember(string $messageId): void
    {
        $this->calls[] = "remember:{$messageId}";
        $this->seen[$messageId] = true;
    }

    public function forget(string $messageId): void
    {
        $this->calls[] = "forget:{$messageId}";
        unset($this->seen[$messageId]);
    }
}

/**
 * A scriptable ClaimingStore: claim() returns $claimGranted, and seen() reflects
 * whether a peer has committed the id (set on remember()). When
 * $commitOnRefusedClaim is set, a refused claim() simulates a peer committing the
 * id between the pre-claim and post-claim seen() checks — the in-flight race the
 * ClaimingDispatch re-check guards against.
 */
final class FakeClaimingStore implements ClaimingStore
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, true> */
    private array $committed = [];

    public function __construct(
        private bool $claimGranted = true,
        private bool $commitOnRefusedClaim = false,
    ) {
    }

    public function seen(string $messageId): bool
    {
        $this->calls[] = "seen:{$messageId}";

        return isset($this->committed[$messageId]);
    }

    public function claim(string $messageId, int $ttlSeconds): bool
    {
        $this->calls[] = "claim:{$messageId}";

        if (! $this->claimGranted && $this->commitOnRefusedClaim) {
            // A peer commits the id concurrently, so the post-claim seen() will be true.
            $this->committed[$messageId] = true;
        }

        return $this->claimGranted;
    }

    public function release(string $messageId): void
    {
        $this->calls[] = "release:{$messageId}";
    }

    public function remember(string $messageId): void
    {
        $this->calls[] = "remember:{$messageId}";
        $this->committed[$messageId] = true;
    }

    public function forget(string $messageId): void
    {
        $this->calls[] = "forget:{$messageId}";
        unset($this->committed[$messageId]);
    }
}

/** A bare message used only to drive the bus; routing is irrelevant to the dedupe. */
final class IdempotentStub
{
}
