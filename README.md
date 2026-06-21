# BabelQueue for Symfony

[![CI](https://github.com/BabelQueue/symfony/actions/workflows/ci.yml/badge.svg)](https://github.com/BabelQueue/symfony/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/babelqueue/symfony.svg)](https://packagist.org/packages/babelqueue/symfony)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

> **Polyglot Queues, Simplified.** A Symfony Messenger serializer that speaks the
> canonical BabelQueue envelope — so your Symfony services exchange messages with
> Laravel, Go, Python, .NET and Node over one strict JSON format, on the broker
> you already run.

This is the Symfony adapter. It plugs into **Symfony Messenger**: you keep
Messenger's transports, handlers, worker and retry — BabelQueue only changes the
**wire format** to the language-agnostic envelope (built by the shared core,
[`babelqueue/php-sdk`](https://packagist.org/packages/babelqueue/php-sdk)). The
full standard is documented at **[babelqueue.com](https://babelqueue.com)**.

## Requirements

- PHP `^8.2`
- Symfony `^6.4 | ^7.0` (Messenger)
- A broker Messenger supports (AMQP/RabbitMQ, Redis, …)

## Installation

```bash
composer require babelqueue/symfony
```

Enable the bundle (if you don't use Symfony Flex) in `config/bundles.php`:

```php
return [
    // ...
    BabelQueue\Symfony\BabelQueueBundle::class => ['all' => true],
];
```

## Configuration

Point a Messenger transport at the BabelQueue serializer, and map inbound URNs to
message classes:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            babel:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'   # e.g. amqp:// or redis://
                serializer: 'babelqueue.messenger.serializer'
        routing:
            'App\Message\OrderCreated': babel
        buses:
            messenger.bus.default:
                middleware:
                    # Auto-forward trace_id from a handled message to any it
                    # dispatches, so a chain of work stays in one trace.
                    - 'babelqueue.messenger.trace_middleware'
```

```yaml
# config/packages/babelqueue.yaml
babelqueue:
    queue: 'orders'            # written to the envelope meta.queue
    messages:                  # urn => message class (needed to consume)
        'urn:babel:orders:created': 'App\Message\OrderCreated'
```

## Idempotency (deduplicate redeliveries)

Brokers deliver **at least once**, so a worker can see the same logical message
twice (a redelivery after a crash, a visibility-timeout expiry, a fan-out). Enable
the idempotency middleware to deduplicate on the envelope's canonical `meta.id`, so
a handler runs **once** per message. It's **opt-in and off by default** — leaving it
disabled changes nothing.

```yaml
# config/packages/babelqueue.yaml
babelqueue:
    idempotency:
        enabled: true          # opt-in; off by default
        store: ~               # service id of a BabelQueue\Idempotency\IdempotencyStore;
                               # null = a bundled in-memory store (single process / tests only)
        ttl: 3600              # in-flight claim TTL in seconds (only used by a ClaimingStore)
```

```yaml
# config/packages/messenger.yaml — register it BEFORE your handlers on the
# consuming bus (alongside the trace middleware).
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware:
                    - 'babelqueue.messenger.idempotency_middleware'
                    - 'babelqueue.messenger.trace_middleware'
```

How it behaves, per inbound delivery (keyed on `meta.id`):

- **first delivery** → the handler runs once; on a clean return the id is recorded.
- **a duplicate** (same `meta.id` already recorded) → the handler is **skipped** and
  the message is acked, so the broker stops redelivering.
- **the handler throws** → the id is **not** recorded and the error propagates, so
  Messenger's retry / failure transport still apply and a later delivery runs again.
- **a message with no usable id** → handled unchanged (fail-open).

The default in-memory store is process-local — fine for a single worker or tests,
but a **fleet** of workers must share one store. Point `store` at a service
implementing `BabelQueue\Idempotency\IdempotencyStore` (from `babelqueue/php-sdk`),
such as the persistent `PdoStore` (Postgres / MySQL / SQLite) or `RedisStore`:

```yaml
# config/services.yaml
services:
    app.babelqueue.idempotency_store:
        class: BabelQueue\Idempotency\RedisStore
        arguments: ['@your.predis.client']
```

```yaml
# config/packages/babelqueue.yaml
babelqueue:
    idempotency:
        enabled: true
        store: 'app.babelqueue.idempotency_store'
```

If the configured store implements `BabelQueue\Idempotency\ClaimingStore` (the
persistent `PdoStore` / `RedisStore` do), the middleware uses an **atomic claim**:
of N concurrent deliveries of the same id, exactly one runs the handler; the rest
are **parked** (redelivered later, not acked) until the winner commits. `ttl` bounds
a crash between claim and commit. This is still at-least-once, never exactly-once —
keep handlers idempotent.

## A message

Implement `BabelQueue\Symfony\Contracts\PolyglotMessage`:

```php
use BabelQueue\Symfony\Contracts\PolyglotMessage;

final class OrderCreated implements PolyglotMessage
{
    public function __construct(public int $orderId) {}

    public function getBabelUrn(): string
    {
        return 'urn:babel:orders:created';
    }

    public function toPayload(): array
    {
        return ['order_id' => $this->orderId];
    }

    public static function fromBabelPayload(array $data): static
    {
        return new self((int) $data['order_id']);
    }
}
```

## Produce & consume

```php
// produce — a normal Messenger dispatch
$bus->dispatch(new OrderCreated(1042));
```

On the wire it becomes the canonical envelope, readable by every BabelQueue SDK:

```json
{
  "job": "urn:babel:orders:created",
  "trace_id": "…",
  "data": { "order_id": 1042 },
  "meta": { "id": "…", "queue": "orders", "lang": "php", "schema_version": 1, "created_at": 1749132727000 },
  "attempts": 0
}
```

```php
// consume — a normal Messenger handler, routed by message class
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class OnOrderCreated
{
    public function __invoke(OrderCreated $message): void
    {
        // ...
    }
}
```

Run the worker as usual: `php bin/console messenger:consume babel`.

## How it maps to Messenger

- **Routing** is Messenger's job: it routes the decoded message class to a handler.
- **Retry** bridges both ways — Messenger's `RedeliveryStamp` ⇄ the envelope's
  top-level `attempts`.
- **Tracing** — the inbound `trace_id` is attached as a `BabelTraceStamp`. With
  `babelqueue.messenger.trace_middleware` on the bus (see config above), any
  message a handler dispatches **automatically** inherits that `trace_id`, so a
  whole chain stays in one trace. A message that pins its own `BabelTraceStamp` or
  implements `HasTraceId` keeps its explicit id.
- **Unknown URN** — a message whose URN isn't mapped throws
  `MessageDecodingFailedException`, so Messenger routes it to your failure
  transport (the idiomatic Symfony behavior).
- **Idempotency** — with `babelqueue.messenger.idempotency_middleware` on the bus
  (opt-in, see above), a redelivery of the same `meta.id` is acked without
  re-running the handler. The id is surfaced on decode as a `BabelMessageIdStamp`.

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT © Muhammet Şafak. See [LICENSE](LICENSE).
