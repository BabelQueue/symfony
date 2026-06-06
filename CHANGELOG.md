# Changelog

All notable changes to `babelqueue/symfony` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The envelope wire format is versioned separately by `meta.schema_version`
(currently **1**) — see the contract at [babelqueue.com](https://babelqueue.com).

## [Unreleased]

### Added
- `Messenger\BabelQueueSerializer` — a Symfony Messenger transport serializer that
  encodes/decodes the canonical BabelQueue envelope via the shared core codec
  (`babelqueue/php-sdk`), so Symfony interoperates with the other SDKs.
- `Contracts\PolyglotMessage` — message contract (`getBabelUrn()`, `toPayload()`,
  `fromBabelPayload()`).
- `Messenger\MessageRegistry` — URN → message-class map for decoding.
- `Messenger\Stamp\BabelTraceStamp` — carries `trace_id` through the pipeline;
  attached on decode, honoured on encode (trace continuation).
- `Messenger\TracePropagationMiddleware` — **automatic** `trace_id` propagation:
  while a received message is handled, any follow-up message dispatched on the bus
  inherits its `trace_id` (unless it pins its own stamp or implements `HasTraceId`).
  Registered as `babelqueue.messenger.trace_middleware`.
- Redelivery ↔ `attempts` bridge (Messenger `RedeliveryStamp` ⇄ envelope `attempts`).
- `BabelQueueBundle` + DI: registers the serializer as
  `babelqueue.messenger.serializer` and the middleware as
  `babelqueue.messenger.trace_middleware`, configured under the `babelqueue` key.

### Notes
- Pre-1.0: the public API may change before the `1.0.0` tag.
- Requires PHP `^8.2`, `babelqueue/php-sdk ^0.1`, and Symfony `^6.4 | ^7.0`.
- Routing/worker/retry remain Messenger's responsibility; this package only owns
  the wire format.

[Unreleased]: https://github.com/BabelQueue/symfony/commits/main
