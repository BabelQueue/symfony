# Changelog

All notable changes to `babelqueue/symfony` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The envelope wire format is versioned separately by `meta.schema_version`
(currently **1**) — see the contract at [babelqueue.com](https://babelqueue.com).

## [Unreleased]

## [1.0.0] - 2026-06-07

**1.0.0 — the public API is now SemVer-stable**: breaking changes require a MAJOR,
following the deprecation policy. The wire envelope is unchanged
(`schema_version: 1`). Full reference at [babelqueue.com](https://babelqueue.com).

### Changed
- Require `babelqueue/php-sdk ^1.0`.

### Internal
- CI runs **PHPStan (level 9)** over `src` and enforces a **>=90% line-coverage
  gate** (`bin/check-coverage.php`); added a DI test for the bundle extension.
  Decode now hardens mixed JSON values (URN via the core `EnvelopeCodec::urn`,
  guarded `attempts`/`trace_id`) — no behaviour change for valid envelopes.

## [0.3.0] - 2026-06-06

### Added
- `Messenger\TracePropagationMiddleware` — **automatic** `trace_id` propagation:
  while a received message is handled, any follow-up message dispatched on the bus
  inherits its `trace_id` (unless it pins its own stamp or implements `HasTraceId`).
  Registered as `babelqueue.messenger.trace_middleware`.

### Changed
- Raise the core dependency to `babelqueue/php-sdk ^0.3`.

### Notes
- The version jumps to **0.3.0** to align the PHP packages (`php-sdk`, `laravel`,
  `symfony`) on one version line. The serializer/codec foundation below shipped
  across 0.1.0–0.2.0; this changelog began per-version sections at 0.3.0.

## [0.1.0] - 2026-06-06

### Added
- `Messenger\BabelQueueSerializer` — a Symfony Messenger transport serializer that
  encodes/decodes the canonical BabelQueue envelope via the shared core codec
  (`babelqueue/php-sdk`), so Symfony interoperates with the other SDKs.
- `Contracts\PolyglotMessage` — message contract (`getBabelUrn()`, `toPayload()`,
  `fromBabelPayload()`).
- `Messenger\MessageRegistry` — URN → message-class map for decoding.
- `Messenger\Stamp\BabelTraceStamp` — carries `trace_id` through the pipeline;
  attached on decode, honoured on encode (trace continuation).
- Redelivery ↔ `attempts` bridge (Messenger `RedeliveryStamp` ⇄ envelope `attempts`).
- `BabelQueueBundle` + DI: registers the serializer as
  `babelqueue.messenger.serializer`, configured under the `babelqueue` key.

### Notes
- Pre-1.0: the public API may change before the `1.0.0` tag.
- Requires PHP `^8.2`, `babelqueue/php-sdk ^0.1`, and Symfony `^6.4 | ^7.0`.
- Routing/worker/retry remain Messenger's responsibility; this package only owns
  the wire format.

[Unreleased]: https://github.com/BabelQueue/symfony/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/BabelQueue/symfony/compare/v0.3.0...v1.0.0
[0.3.0]: https://github.com/BabelQueue/symfony/compare/v0.2.0...v0.3.0
[0.1.0]: https://github.com/BabelQueue/symfony/releases/tag/v0.1.0
