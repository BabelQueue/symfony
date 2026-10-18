<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Tests\Messenger;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Symfony\Contracts\PolyglotMessage;
use BabelQueue\Symfony\Messenger\BabelQueueSerializer;
use BabelQueue\Symfony\Messenger\MessageRegistry;
use BabelQueue\Symfony\Messenger\Stamp\BabelMessageIdStamp;
use BabelQueue\Symfony\Messenger\Stamp\BabelTraceStamp;
use BabelQueue\Symfony\Tests\Support\ConformanceResults;
use BabelQueue\Symfony\Tests\Support\CoreConformanceSections;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Runs the vendored cross-SDK conformance suite (tests/conformance, a byte-for-byte copy of
 * the canonical conformance/ directory) through the Symfony adapter's own path:
 * {@see BabelQueueSerializer::decode()} and {@see BabelQueueSerializer::encode()}.
 *
 * The adapter is a Messenger serializer and owns no transport, so it carries the `core` sections
 * only. The ones that exercise the serializer (`cases`, `data_shape`, `forbidden_keys`, `roundtrip`)
 * are driven here; the rest delegate to the core codec through {@see CoreConformanceSections}. Every
 * case is recorded for `results.json` (ADR-0032) by {@see ConformanceResults::each()}.
 *
 * The serializer is *typed*: a decoded message becomes a PHP object and a retry re-encodes
 * a fresh envelope from that object, so unknown keys survive a retry through a stamp rather than
 * through the message. Objects are parsed as stdClass so {} is never confused with [] and key
 * order is ignored.
 */
final class SerializerConformanceTest extends TestCase
{
    use CoreConformanceSections;

    /** @var array<int, array{string, string}> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->warnings = [];
        EnvelopeCodec::setWarningHandler(function (string $message, string $pointer): void {
            $this->warnings[] = [$message, $pointer];
        });
    }

    protected function tearDown(): void
    {
        EnvelopeCodec::setWarningHandler(null);
    }

    private function serializer(): BabelQueueSerializer
    {
        return new BabelQueueSerializer(
            new MessageRegistry([
                'urn:babel:orders:created' => ConformanceOrderCreated::class,
                'urn:babel:catalog:item.indexed' => ConformanceItemIndexed::class,
                'urn:acme:a' => ConformanceAcmeA::class,
                'urn:acme:b' => ConformanceAcmeB::class,
                // forbidden-key-job-nonascii-space-and-urn: U+2003 is not ASCII-blank, so it IS the URN.
                "\u{2003}" => ConformanceEmSpace::class,
            ]),
            'orders',
        );
    }

    // ------------------------------------------------------------------ decode

    /**
     * Valid cases decode to the mapped message with its stamps and re-encode to a conformant envelope;
     * invalid ones (missing urn, wrong-case `JOB`, unsupported `meta.schema_version`) throw, so
     * Messenger routes them to the failure transport instead of running a handler.
     */
    public function test_cases_section(): void
    {
        ConformanceResults::each('cases', function (array $case): void {
            $body = $this->fixture($case);

            if (! (bool) $case['valid']) {
                try {
                    $this->serializer()->decode(['body' => $body]);
                    self::fail('an invalid envelope must be rejected');
                } catch (MessageDecodingFailedException $e) {
                    if ($case['name'] === 'invalid-unknown-schema-version') {
                        self::assertStringContainsString('schema_version', $e->getMessage());
                    }
                }

                return;
            }

            $expect = (array) $case['expect'];
            $source = self::parse($body);

            $envelope = $this->serializer()->decode(['body' => $body]);
            $message = $envelope->getMessage();

            self::assertInstanceOf(ConformanceMessage::class, $message);
            self::assertSame($expect['urn'], $message->getBabelUrn(), 'urn');
            self::assertTrue(self::deepEqual(self::toObject($expect['data']), self::toObject($message->payload)), 'data');

            $redelivery = $envelope->last(RedeliveryStamp::class);
            if ((int) $expect['attempts'] > 0) {
                self::assertInstanceOf(RedeliveryStamp::class, $redelivery);
                self::assertSame($expect['attempts'], $redelivery->getRetryCount(), 'attempts');
            } else {
                self::assertNull($redelivery, 'no redelivery on a first delivery');
            }

            $trace = $envelope->last(BabelTraceStamp::class);
            self::assertInstanceOf(BabelTraceStamp::class, $trace);
            self::assertSame($source->trace_id, $trace->traceId, 'trace_id preserved');

            $id = $envelope->last(BabelMessageIdStamp::class);
            self::assertInstanceOf(BabelMessageIdStamp::class, $id);
            self::assertSame($source->meta->id, $id->messageId, 'meta.id exposed');

            $encoded = $this->serializer()->encode($envelope);
            $out = self::parse($encoded['body']);

            self::assertTrue(EnvelopeCodec::accepts(EnvelopeCodec::decode($encoded['body'])), 'own output is accepted');
            self::assertSame($expect['urn'], $out->job, 'urn');
            self::assertTrue(self::deepEqual(self::toObject($expect['data']), $out->data), 'encoded data');
            self::assertSame($source->trace_id, $out->trace_id, 'trace_id forwarded unchanged');
            self::assertSame($expect['attempts'], $out->attempts, 'attempts');
            self::assertSame(1, $out->meta->schema_version);
            foreach (['/timestamp', '/meta/ts', '/meta/source', '/meta/max_retries', '/meta/attempts'] as $pointer) {
                self::assertFalse(self::has($out, $pointer), $pointer);
            }
        });
    }

    /** Both data_shape modes: `encode` runs the serializer's producer path, `decode` the consume rejection. */
    public function test_data_shape_section(): void
    {
        ConformanceResults::each('data_shape', function (array $case): void {
            if ($case['mode'] === 'encode') {
                self::assertSame('urn:babel:orders:created', $case['urn'], 'fixture URN is the one the stub maps');
                $message = new ConformanceOrderCreated((array) $case['data']);

                $out = self::parse($this->serializer()->encode(new Envelope($message))['body']);
                $raw = json_encode($out->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                self::assertSame($case['expect_encoded_data_json'], $raw, 'encoded data');

                return;
            }

            self::assertFalse((bool) $case['valid']);

            try {
                $this->serializer()->decode(['body' => $this->fixture($case)]);
                self::fail('a list-shaped data block must be rejected');
            } catch (MessageDecodingFailedException) {
                self::assertNotEmpty($this->warnings, 'the rejection is surfaced as a warning');
            }
        });
    }

    /**
     * Forbidden keys warn on decode and are never re-emitted, neither by a plain re-encode nor by a
     * retry (which must also not resurrect the old schema version).
     */
    public function test_forbidden_keys_section(): void
    {
        ConformanceResults::each('forbidden_keys', function (array $case): void {
            $forbidden = (string) $case['forbidden_key'];
            self::assertSame('warn', $case['expect']);
            self::assertNotEmpty((array) $case['expect_absent_after_reencode']);

            $this->warnings = [];
            $envelope = $this->serializer()->decode(['body' => $this->fixture($case)]);
            $named = array_filter($this->warnings, static fn (array $w): bool => str_contains($w[0], $forbidden));
            self::assertNotEmpty($named, 'a warning must name ' . $forbidden);

            $this->warnings = [];
            $out = self::parse($this->serializer()->encode($envelope)['body']);
            self::assertSame([], $this->warnings, 'nothing left to strip on re-encode');

            $retry = self::parse($this->serializer()->encode($envelope->with(new RedeliveryStamp(1)))['body']);

            foreach ((array) $case['expect_absent_after_reencode'] as $pointer) {
                self::assertFalse(self::has($out, (string) $pointer), $pointer . ' must be absent');
                self::assertFalse(self::has($retry, (string) $pointer), $pointer . ' must be absent after a retry');
            }
            self::assertSame(1, $retry->meta->schema_version);
        });
    }

    // ------------------------------------------------------------------- retry

    /**
     * Messenger retries by re-encoding the message with a RedeliveryStamp, so a retry keeps the URN,
     * `data` (an empty object stays `{}`), the trace_id and the original `meta.id`, and moves
     * attempts to attempts + 1; unknown top-level / meta keys and `meta.lang` ride in a
     * BabelEnvelopeStamp. The adapter has no DLQ helper of its own (dead-lettering is Messenger's
     * failure transport), so `apply_dead_letter` is run as the plain decode -> attempts+1 -> encode
     * chain the manifest allows for a runner that does not know it.
     */
    public function test_roundtrip_section(): void
    {
        ConformanceResults::each('roundtrip', function (\stdClass $case): void {
            $name = (string) $case->name;
            $body = $this->fixture($case);
            $source = self::parse($body);

            $retry = $this->serializer()->decode(['body' => $body])->with(new RedeliveryStamp($source->attempts + 1));
            $out = self::parse($this->serializer()->encode($retry)['body']);

            $violations = [
                ...self::pointerViolations($out, $case->expect_preserved ?? (object) [], $name),
                ...self::absentViolations($out, (array) ($case->expect_absent_after_reencode ?? []), $name),
            ];
            self::assertSame([], $violations, implode("\n", $violations));

            self::assertSame($case->expect_attempts, $out->attempts, 'attempts');
            self::assertSame($source->trace_id, $out->trace_id, 'trace_id');
            self::assertTrue(self::deepEqual($source->data, $out->data), 'data');
            if (isset($source->meta->id)) {
                self::assertSame($source->meta->id, $out->meta->id, 'meta.id is the stable per-message identity');
            }
        }, null, true);
    }

    /** Normalise a decoded (assoc) value to the stdClass form used for strict comparison. */
    private static function toObject(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, 512, JSON_THROW_ON_ERROR);
    }
}

/** A lossless typed message: it carries the decoded `data` block verbatim. */
abstract class ConformanceMessage implements PolyglotMessage
{
    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload = [])
    {
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return $this->payload;
    }

    /** @param array<string, mixed> $data */
    public static function fromBabelPayload(array $data): static
    {
        return new static($data);
    }
}

final class ConformanceOrderCreated extends ConformanceMessage
{
    public function getBabelUrn(): string
    {
        return 'urn:babel:orders:created';
    }
}

final class ConformanceItemIndexed extends ConformanceMessage
{
    public function getBabelUrn(): string
    {
        return 'urn:babel:catalog:item.indexed';
    }
}

final class ConformanceAcmeA extends ConformanceMessage
{
    public function getBabelUrn(): string
    {
        return 'urn:acme:a';
    }
}

final class ConformanceAcmeB extends ConformanceMessage
{
    public function getBabelUrn(): string
    {
        return 'urn:acme:b';
    }
}

final class ConformanceEmSpace extends ConformanceMessage
{
    public function getBabelUrn(): string
    {
        return "\u{2003}";
    }
}
