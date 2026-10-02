# Changelog

All notable changes to `babelqueue/symfony` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The envelope wire format is versioned separately by `meta.schema_version`
(currently **1**) — see the contract at [babelqueue.com](https://babelqueue.com).

## [Unreleased]

## [1.2.1] - 2026-10-01

### Changed
- **Release workflow: Packagist credentials moved out of the URL.** The optional
  "Notify Packagist" step now sends `Authorization: Bearer USERNAME:API_TOKEN`
  (Packagist's documented scheme) to `https://packagist.org/api/update-package`
  instead of passing `username` / `apiToken` as query parameters, so the token can
  no longer surface in request logs, proxies or curl error output. Secret names are
  unchanged (`PACKAGIST_USERNAME`, `PACKAGIST_TOKEN`).
- Added `.github/dependabot.yml` (weekly `composer` and `github-actions` updates).
- **Documented: retries on Amazon SQS are Messenger's.** The README now states
  that on SQS, Messenger's retry strategy re-sends a failed message (with a
  `DelayStamp`) and deletes the original rather than calling
  `ChangeMessageVisibility`. It recommends a Messenger `failure_transport` for
  messages that exhaust their retries, and states that an undecodable (poison)
  body never reaches the failure transport: the SQS receiver rejects it, which
  by default is `DeleteMessage`, so it is lost and an SQS RedrivePolicy cannot
  catch it. On Symfony 7.4+ `delete_on_rejection: false` plus a RedrivePolicy
  keeps it (with Messenger retries off and no `failure_transport` on that
  transport, which would otherwise collect one copy per receive). The Unknown URN note no longer claims
  that such a message is routed to the failure transport. No code change.

## [1.2.0] - 2026-06-21

### Added
- **Idempotent message handling (ADR-0022).** An opt-in Messenger middleware,
  `BabelQueue\Symfony\Messenger\IdempotencyMiddleware`, deduplicates a redelivered
  BabelQueue message on its canonical `meta.id` so a handler runs once per logical
  message under the broker's at-least-once delivery. It is the Symfony-idiomatic
  wrapper around the core `BabelQueue\Idempotency\Idempotent::wrap()` /
  `ClaimingDispatch::wrap()` helpers (from `babelqueue/php-sdk`), adapted to the
  Messenger stack:
  - Acts only on the **receive** path (a delivery carrying a `ReceivedStamp`); a
    fresh outbound dispatch passes through untouched. A first delivery is handled
    once and recorded; a duplicate is **acked without re-handling**; a thrown
    handler leaves the id unrecorded so Messenger's retry / failure transport (and
    DLQ) still apply; an id-less message is handled unchanged (fail-open).
  - When the configured store implements `BabelQueue\Idempotency\ClaimingStore`
    (the persistent `PdoStore` / `RedisStore`), the stronger atomic
    claim/commit/release lifecycle is used: exactly one of N concurrent deliveries
    of the same id runs the handler, and a delivery that loses to an in-flight peer
    is **parked** (it throws `ClaimParkedException`, so Messenger does not ack it
    and the broker redelivers later). Still at-least-once, never exactly-once.
  - Configured under the `babelqueue.idempotency` key (`enabled` — default `false`,
    so behavior is unchanged when off; `store` — a service id, defaulting to a
    bundled in-memory store for single-process / test use; `ttl` — the in-flight
    claim TTL honoured by a `ClaimingStore`). Exposed for the bus as the public
    alias `babelqueue.messenger.idempotency_middleware`.
- The serializer now surfaces the envelope's `meta.id` on decode as a
  `BabelQueue\Symfony\Messenger\Stamp\BabelMessageIdStamp`, so consume-side
  middleware can deduplicate on the stable per-message identity. The frozen wire
  envelope is unchanged (`schema_version` stays **1**) — this is a consume-side
  artifact only.

### Changed
- Bumped the `babelqueue/php-sdk` requirement to `^1.15.0` (the version that ships
  the `BabelQueue\Idempotency` stores and claim helpers this adapter wires).

## [1.1.1] - 2026-06-14

### Fixed
- **The bundle now boots in a real Symfony kernel under the `babelqueue` config key.**
  `BabelQueueBundle` did not override `getContainerExtension()`, so on a real kernel boot the
  auto-derived extension alias (`babel_queue`) did not match the `babelqueue` config key and the
  kernel threw a `LogicException`. The bundle now returns `BabelQueueExtension` explicitly. (The
  prior tests exercised the extension directly and never booted a kernel, so they missed it.)

### Internal
- Added a **bundle integration test** that boots a minimal test kernel registering `BabelQueueBundle`
  and asserts the serializer/middleware services resolve and the `babelqueue` config (default queue +
  URN message registry) is read.
- **CI:** `release.yml` now runs the **≥90% coverage gate** (mirroring `ci.yml`).

## [1.1.0] - 2026-06-14

### Changed
- **Aligned the Messenger serializer's transport headers to the canonical `bq-` projection.**
  `BabelQueueSerializer::encode()` now emits `bq-job` (URN), `bq-trace-id`, `bq-message-id`,
  `bq-schema-version`, `bq-source-lang` and `bq-attempts` — the same `bq-` header set the
  Kafka/SQS/Pulsar bindings use — instead of the non-standard `X-Babel-Urn` / `X-Babel-Trace-Id` /
  `X-Babel-Schema-Version` (which also omitted source-lang, attempts and message-id). This makes
  Symfony-produced headers **consistent with the rest of the ecosystem** and lets a transport that
  maps serializer headers onto native broker headers (e.g. a Kafka Messenger transport) carry the
  §6-style `bq-` metadata, so a cross-language consumer can route on `bq-job` without decoding the
  body. The **wire envelope is unchanged** (`schema_version: 1`, byte-identical body) — interop has
  always been **body-authoritative**, so consumers that decode the body are unaffected; only the
  redundant transport-header *names* changed. **Migration:** if you read `X-Babel-*` headers off the
  transport, switch to the `bq-*` names. Note: for transports where the native projection is owned
  by Messenger itself (AMQP `type`, SQS `MessageAttributes`), routing stays body-authoritative
  regardless of these serializer headers.

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

[Unreleased]: https://github.com/BabelQueue/symfony/compare/v1.1.1...HEAD
[1.1.1]: https://github.com/BabelQueue/symfony/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/BabelQueue/symfony/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/BabelQueue/symfony/compare/v0.3.0...v1.0.0
[0.3.0]: https://github.com/BabelQueue/symfony/compare/v0.2.0...v0.3.0
[0.1.0]: https://github.com/BabelQueue/symfony/releases/tag/v0.1.0
