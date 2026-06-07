<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Messenger;

use BabelQueue\Contracts\HasTraceId;
use BabelQueue\Symfony\Messenger\Stamp\BabelTraceStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Automatically forwards the BabelQueue `trace_id` across re-dispatches, so a
 * whole chain of work stays in one distributed trace without callers threading
 * the id by hand (golden rule: preserve and forward `trace_id` across every hop).
 *
 * While a message received from a transport is being handled, its trace id is
 * the ambient context. Any *new* message dispatched during that handling
 * inherits it — unless the message already pins a {@see BabelTraceStamp} or
 * declares its own id via {@see HasTraceId}, in which case the explicit id wins.
 * Outside any handling context (e.g. a controller dispatch) nothing is added, so
 * the serializer mints a fresh trace as before.
 *
 * Register it as the FIRST middleware on every bus whose handlers dispatch
 * follow-up messages:
 *
 *     framework:
 *       messenger:
 *         buses:
 *           messenger.bus.default:
 *             middleware:
 *               - 'babelqueue.messenger.trace_middleware'
 */
final class TracePropagationMiddleware implements MiddlewareInterface
{
    /**
     * Trace ids of the messages currently being handled, innermost last. A stack
     * (not a single value) so nested handling restores the outer context.
     *
     * @var list<string>
     */
    private array $handling = [];

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // A message coming off a transport: open a handling context carrying its
        // trace id for the duration, so nested dispatches can inherit it.
        if ($envelope->last(ReceivedStamp::class) !== null) {
            $inbound = $envelope->last(BabelTraceStamp::class);
            $this->handling[] = $inbound instanceof BabelTraceStamp ? $inbound->traceId : '';

            try {
                return $stack->next()->handle($envelope, $stack);
            } finally {
                array_pop($this->handling);
            }
        }

        // An outbound dispatch: inherit the ambient trace unless the message
        // already carries one (explicit stamp) or declares its own (HasTraceId).
        if ($this->shouldInherit($envelope)) {
            $envelope = $envelope->with(new BabelTraceStamp($this->current()));
        }

        return $stack->next()->handle($envelope, $stack);
    }

    private function shouldInherit(Envelope $envelope): bool
    {
        if ($this->current() === '') {
            return false;
        }

        if ($envelope->last(BabelTraceStamp::class) !== null) {
            return false;
        }

        $message = $envelope->getMessage();

        return ! ($message instanceof HasTraceId && trim((string) $message->getBabelTraceId()) !== '');
    }

    /** The innermost handling trace id, or '' when not inside a handling context. */
    private function current(): string
    {
        return $this->handling === [] ? '' : $this->handling[array_key_last($this->handling)];
    }
}
