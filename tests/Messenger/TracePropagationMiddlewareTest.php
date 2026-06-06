<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Tests\Messenger;

use BabelQueue\Contracts\HasTraceId;
use BabelQueue\Symfony\Messenger\Stamp\BabelTraceStamp;
use BabelQueue\Symfony\Messenger\TracePropagationMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Drives the middleware through a real in-memory bus (no broker): a "received"
 * message is handled, its handler dispatches a follow-up, and we assert the
 * follow-up inherits the inbound trace — while explicit ids are left untouched.
 */
final class TracePropagationMiddlewareTest extends TestCase
{
    public function test_follow_up_dispatched_while_handling_inherits_the_inbound_trace(): void
    {
        $capture = new CaptureMiddleware();
        $bus = $this->bus($capture, FollowUpStub::class);

        $bus->dispatch(new Envelope(new InboundStub(), [
            new ReceivedStamp('async'),
            new BabelTraceStamp('trace-inbound'),
        ]));

        $stamp = $capture->envelopeFor(FollowUpStub::class)?->last(BabelTraceStamp::class);
        $this->assertInstanceOf(BabelTraceStamp::class, $stamp);
        $this->assertSame('trace-inbound', $stamp->traceId);
    }

    public function test_follow_up_with_its_own_stamp_is_not_overridden(): void
    {
        $capture = new CaptureMiddleware();
        $bus = $this->bus($capture, FollowUpStub::class, followUpStamps: [new BabelTraceStamp('explicit')]);

        $bus->dispatch(new Envelope(new InboundStub(), [
            new ReceivedStamp('async'),
            new BabelTraceStamp('trace-inbound'),
        ]));

        $stamp = $capture->envelopeFor(FollowUpStub::class)?->last(BabelTraceStamp::class);
        $this->assertSame('explicit', $stamp?->traceId);
    }

    public function test_follow_up_declaring_its_own_trace_via_has_trace_id_is_not_overridden(): void
    {
        $capture = new CaptureMiddleware();
        $bus = $this->bus($capture, SelfTracedFollowUpStub::class);

        $bus->dispatch(new Envelope(new InboundStub(), [
            new ReceivedStamp('async'),
            new BabelTraceStamp('trace-inbound'),
        ]));

        // The middleware adds no stamp; the serializer would later use the
        // message's own getBabelTraceId(), so no BabelTraceStamp is attached here.
        $this->assertNull($capture->envelopeFor(SelfTracedFollowUpStub::class)?->last(BabelTraceStamp::class));
    }

    public function test_dispatch_outside_a_handling_context_gets_no_trace_stamp(): void
    {
        $capture = new CaptureMiddleware();
        $bus = $this->bus($capture, FollowUpStub::class);

        // No ReceivedStamp => not a handling context => nothing to inherit.
        $bus->dispatch(new FollowUpStub());

        $this->assertNull($capture->envelopeFor(FollowUpStub::class)?->last(BabelTraceStamp::class));
    }

    public function test_inbound_without_a_trace_does_not_invent_one_for_follow_ups(): void
    {
        $capture = new CaptureMiddleware();
        $bus = $this->bus($capture, FollowUpStub::class);

        $bus->dispatch(new Envelope(new InboundStub(), [new ReceivedStamp('async')]));

        $this->assertNull($capture->envelopeFor(FollowUpStub::class)?->last(BabelTraceStamp::class));
    }

    /**
     * A bus of [trace middleware, capture, handler]. The InboundStub handler
     * dispatches one follow-up (of $followUpClass) back onto the same bus.
     *
     * @param  class-string  $followUpClass
     * @param  list<\Symfony\Component\Messenger\Stamp\StampInterface>  $followUpStamps
     */
    private function bus(CaptureMiddleware $capture, string $followUpClass, array $followUpStamps = []): MessageBus
    {
        $busRef = null;

        $handlers = new HandlersLocator([
            InboundStub::class => [static function (InboundStub $message) use (&$busRef, $followUpClass, $followUpStamps): void {
                /** @var MessageBusInterface $busRef */
                $busRef->dispatch(new Envelope(new $followUpClass(), $followUpStamps));
            }],
            FollowUpStub::class => [static fn (FollowUpStub $message) => null],
            SelfTracedFollowUpStub::class => [static fn (SelfTracedFollowUpStub $message) => null],
        ]);

        $bus = new MessageBus([
            new TracePropagationMiddleware(),
            $capture,
            new HandleMessageMiddleware($handlers),
        ]);

        $busRef = $bus;

        return $bus;
    }
}

/** Records every envelope that flows past, for post-hoc stamp assertions. */
final class CaptureMiddleware implements MiddlewareInterface
{
    /** @var list<Envelope> */
    public array $envelopes = [];

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $this->envelopes[] = $envelope;

        return $stack->next()->handle($envelope, $stack);
    }

    /** @param class-string $messageClass */
    public function envelopeFor(string $messageClass): ?Envelope
    {
        foreach ($this->envelopes as $envelope) {
            if ($envelope->getMessage() instanceof $messageClass) {
                return $envelope;
            }
        }

        return null;
    }
}

final class InboundStub
{
}

final class FollowUpStub
{
}

final class SelfTracedFollowUpStub implements HasTraceId
{
    public function getBabelTraceId(): ?string
    {
        return 'self-declared-trace';
    }
}
