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

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT © Muhammet Şafak. See [LICENSE](LICENSE).
