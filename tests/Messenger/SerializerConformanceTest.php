<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Tests\Messenger;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Symfony\Contracts\PolyglotMessage;
use BabelQueue\Symfony\Messenger\BabelQueueSerializer;
use BabelQueue\Symfony\Messenger\MessageRegistry;
use BabelQueue\Symfony\Messenger\Stamp\BabelMessageIdStamp;
use BabelQueue\Symfony\Messenger\Stamp\BabelTraceStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Runs the vendored cross-SDK conformance suite (tests/conformance, a byte-for-byte copy of
 * the canonical conformance/ directory) through the Symfony adapter's own path:
 * {@see BabelQueueSerializer::decode()} and {@see BabelQueueSerializer::encode()}.
 *
 * The serializer is *typed*: a decoded message becomes a PHP object and a retry re-encodes
 * a fresh envelope from that object. So the manifest's "unknown keys survive a re-emit"
 * guarantee cannot hold here by construction; it is isolated in its own self-healing test.
 *
 * Objects are parsed as stdClass so {} is never confused with [] and key order is ignored.
 */
final class SerializerConformanceTest extends TestCase
{
    private const DIR = __DIR__ . '/../conformance';

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

    public function test_valid_cases_decode_to_the_mapped_message_with_stamps(): void
    {
        foreach ($this->cases('cases', true) as $case) {
            $name = (string) $case['name'];
            $expect = (array) $case['expect'];
            $source = self::parse($this->fixture($case));

            $envelope = $this->serializer()->decode(['body' => $this->fixture($case)]);
            $message = $envelope->getMessage();

            self::assertInstanceOf(ConformanceMessage::class, $message, $name);
            self::assertSame($expect['urn'], $message->getBabelUrn(), $name . ': urn');
            self::assertTrue(self::deepEqual(self::toObject($expect['data']), self::toObject($message->payload)), $name . ': data');

            $redelivery = $envelope->last(RedeliveryStamp::class);
            if ((int) $expect['attempts'] > 0) {
                self::assertInstanceOf(RedeliveryStamp::class, $redelivery, $name);
                self::assertSame($expect['attempts'], $redelivery->getRetryCount(), $name . ': attempts');
            } else {
                self::assertNull($redelivery, $name . ': no redelivery on a first delivery');
            }

            $trace = $envelope->last(BabelTraceStamp::class);
            self::assertInstanceOf(BabelTraceStamp::class, $trace, $name);
            self::assertSame($source->trace_id, $trace->traceId, $name . ': trace_id preserved');

            $id = $envelope->last(BabelMessageIdStamp::class);
            self::assertInstanceOf(BabelMessageIdStamp::class, $id, $name);
            self::assertSame($source->meta->id, $id->messageId, $name . ': meta.id exposed');
        }
    }

    public function test_missing_urn_is_rejected(): void
    {
        $case = $this->caseNamed('cases', 'invalid-missing-urn');
        self::assertFalse((bool) $case['valid']);

        $this->expectException(MessageDecodingFailedException::class);
        $this->serializer()->decode(['body' => $this->fixture($case)]);
    }

    public function test_list_shaped_data_is_rejected(): void
    {
        $case = $this->caseNamed('data_shape', 'data-array-rejected');
        self::assertFalse((bool) $case['valid']);

        try {
            $this->serializer()->decode(['body' => $this->fixture($case)]);
            self::fail('a list-shaped data block must be rejected');
        } catch (MessageDecodingFailedException) {
            self::assertNotEmpty($this->warnings, 'the rejection is surfaced as a warning');
        }
    }

    /**
     * Contract: "Consumers MUST reject (or quarantine) versions they do not understand"
     * (`invalid-unknown-schema-version`): decode throws, so Messenger routes it to the
     * failure transport instead of running a handler.
     */
    public function test_unsupported_schema_version_is_rejected(): void
    {
        $case = $this->caseNamed('cases', 'invalid-unknown-schema-version');
        self::assertFalse((bool) $case['valid']);

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('schema_version');
        $this->serializer()->decode(['body' => $this->fixture($case)]);
    }

    public function test_forbidden_keys_warn_on_decode_and_are_never_re_emitted(): void
    {
        foreach ($this->cases('forbidden_keys') as $case) {
            $name = (string) $case['name'];
            $forbidden = (string) $case['forbidden_key'];
            self::assertSame('warn', $case['expect'], $name);

            $this->warnings = [];
            $envelope = $this->serializer()->decode(['body' => $this->fixture($case)]);
            $named = array_filter($this->warnings, static fn (array $w): bool => str_contains($w[0], $forbidden));
            self::assertNotEmpty($named, $name . ': a warning must name ' . $forbidden);

            $this->warnings = [];
            $out = self::parse($this->serializer()->encode($envelope)['body']);
            self::assertSame([], $this->warnings, $name . ': nothing left to strip on re-encode');
            foreach ((array) $case['expect_absent_after_reencode'] as $pointer) {
                self::assertFalse(self::has($out, (string) $pointer), $name . ': ' . $pointer . ' must be absent');
            }
        }
    }

    // ------------------------------------------------------------------ encode

    public function test_decode_then_encode_is_a_conformant_envelope(): void
    {
        foreach ($this->cases('cases', true) as $case) {
            $name = (string) $case['name'];
            $expect = (array) $case['expect'];
            $source = self::parse($this->fixture($case));

            $encoded = $this->serializer()->encode($this->serializer()->decode(['body' => $this->fixture($case)]));
            $out = self::parse($encoded['body']);

            self::assertTrue(EnvelopeCodec::accepts(EnvelopeCodec::decode($encoded['body'])), $name . ': own output is accepted');
            self::assertSame($expect['urn'], $out->job, $name . ': urn');
            self::assertTrue(self::deepEqual(self::toObject($expect['data']), $out->data), $name . ': data');
            self::assertSame($source->trace_id, $out->trace_id, $name . ': trace_id forwarded unchanged');
            self::assertSame($expect['attempts'], $out->attempts, $name . ': attempts');
            self::assertSame(1, $out->meta->schema_version, $name);
            foreach (['/timestamp', '/meta/ts', '/meta/source', '/meta/max_retries', '/meta/attempts'] as $pointer) {
                self::assertFalse(self::has($out, $pointer), $name . ': ' . $pointer);
            }
        }
    }

    public function test_data_shape_encode_cases_are_emitted_through_the_serializer(): void
    {
        foreach ($this->cases('data_shape') as $case) {
            if ($case['mode'] !== 'encode') {
                continue;
            }
            $name = (string) $case['name'];

            self::assertSame('urn:babel:orders:created', $case['urn'], $name . ': fixture URN is the one the stub maps');
            $message = new ConformanceOrderCreated((array) $case['data']);

            $out = self::parse($this->serializer()->encode(new Envelope($message))['body']);
            $raw = json_encode($out->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            self::assertSame($case['expect_encoded_data_json'], $raw, $name . ': encoded data');
        }
    }

    // ------------------------------------------------------------------- retry

    /**
     * Messenger retries by re-encoding the message with a RedeliveryStamp, so what a retry
     * keeps is: the URN, `data` (an empty object stays `{}`), the trace_id, and attempts + 1.
     */
    public function test_roundtrip_cases_keep_attempts_data_and_trace_on_a_retry(): void
    {
        foreach ($this->cases('roundtrip') as $case) {
            $name = (string) $case['name'];
            $source = self::parse($this->fixture($case));

            $decoded = $this->serializer()->decode(['body' => $this->fixture($case)]);
            $retry = $decoded->with(new RedeliveryStamp($source->attempts + 1));
            $out = self::parse($this->serializer()->encode($retry)['body']);

            self::assertSame($case['expect_attempts'], $out->attempts, $name . ': attempts');
            self::assertSame($source->trace_id, $out->trace_id, $name . ': trace_id');
            self::assertTrue(self::deepEqual($source->data, $out->data), $name . ': data');
            foreach ($this->expectedPreserved($name) as $pointer => $expected) {
                if ($pointer === '/data') {
                    self::assertTrue(self::deepEqual($expected, self::pointer($out, $pointer, $name)), $name . ': /data must stay an object');
                }
            }
        }
    }

    /**
     * Contract (roundtrip section): unknown top-level / meta keys, the original `meta.id` and
     * `meta.lang` MUST survive a re-emit. The serializer carries them in a BabelEnvelopeStamp.
     */
    public function test_roundtrip_unknown_keys_survive_a_retry(): void
    {
        foreach ($this->cases('roundtrip') as $case) {
            $name = (string) $case['name'];
            $source = self::parse($this->fixture($case));

            $retry = $this->serializer()->decode(['body' => $this->fixture($case)])->with(new RedeliveryStamp($source->attempts + 1));
            $out = self::parse($this->serializer()->encode($retry)['body']);

            foreach ($this->expectedPreserved($name) as $pointer => $expected) {
                self::assertTrue(self::has($out, $pointer), $name . ': ' . $pointer . ' must survive');
                self::assertTrue(self::deepEqual($expected, self::pointer($out, $pointer, $name)), $name . ': ' . $pointer . ' must keep its value and JSON type');
            }

            if (isset($source->meta->id)) {
                self::assertSame($source->meta->id, $out->meta->id, $name . ': meta.id is the stable per-message identity');
            }
        }
    }

    public function test_a_retry_does_not_resurrect_forbidden_keys_or_the_old_schema_version(): void
    {
        foreach ($this->cases('forbidden_keys') as $case) {
            $name = (string) $case['name'];
            $retry = $this->serializer()->decode(['body' => $this->fixture($case)])->with(new RedeliveryStamp(1));
            $out = self::parse($this->serializer()->encode($retry)['body']);

            foreach ((array) $case['expect_absent_after_reencode'] as $pointer) {
                self::assertFalse(self::has($out, (string) $pointer), $name . ': ' . $pointer);
            }
            self::assertSame(1, $out->meta->schema_version, $name);
        }
    }

    // ----------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $path = self::DIR . '/manifest.json';
        self::assertFileExists($path, 'vendored conformance suite is missing');

        /** @var array<string, mixed> */
        return (array) json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    private function cases(string $section, ?bool $valid = null): array
    {
        $block = $this->manifest()[$section] ?? null;
        $cases = is_array($block) ? ($block['cases'] ?? $block) : [];
        self::assertNotEmpty($cases, 'manifest has no ' . $section . ' cases');

        $cases = array_values(array_filter(
            $cases,
            static fn (array $c): bool => $valid === null || (bool) ($c['valid'] ?? true) === $valid,
        ));
        self::assertNotEmpty($cases, $section . ': no matching cases');

        /** @var list<array<string, mixed>> */
        return $cases;
    }

    /** @return array<string, mixed> */
    private function caseNamed(string $section, string $name): array
    {
        foreach ($this->cases($section) as $case) {
            if ($case['name'] === $name) {
                return $case;
            }
        }

        self::fail($section . ' case not found: ' . $name);
    }

    /**
     * `expect_preserved` re-read with objects kept as stdClass, so {} stays {}.
     *
     * @return array<string, mixed>
     */
    private function expectedPreserved(string $name): array
    {
        $raw = json_decode((string) file_get_contents(self::DIR . '/manifest.json'), false, 512, JSON_THROW_ON_ERROR);
        foreach ($raw->roundtrip->cases as $case) {
            if ($case->name === $name) {
                return get_object_vars($case->expect_preserved);
            }
        }

        self::fail('roundtrip case not found: ' . $name);
    }

    /** @param array<string, mixed> $case */
    private function fixture(array $case): string
    {
        $path = self::DIR . '/' . (string) $case['file'];
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private static function parse(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    /** Normalise a decoded (assoc) value to the stdClass form used for strict comparison. */
    private static function toObject(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private static function tokens(string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }

        return array_map(
            static fn (string $t): string => str_replace(['~1', '~0'], ['/', '~'], $t),
            array_slice(explode('/', $pointer), 1),
        );
    }

    private static function has(mixed $doc, string $pointer): bool
    {
        foreach (self::tokens($pointer) as $token) {
            if ($doc instanceof \stdClass && property_exists($doc, $token)) {
                $doc = $doc->{$token};
            } elseif (is_array($doc) && ctype_digit($token) && array_key_exists((int) $token, $doc)) {
                $doc = $doc[(int) $token];
            } else {
                return false;
            }
        }

        return true;
    }

    private static function pointer(mixed $doc, string $pointer, string $name): mixed
    {
        self::assertTrue(self::has($doc, $pointer), $name . ': ' . $pointer . ' missing');

        foreach (self::tokens($pointer) as $token) {
            $doc = $doc instanceof \stdClass ? $doc->{$token} : $doc[(int) $token];
        }

        return $doc;
    }

    private static function deepEqual(mixed $a, mixed $b): bool
    {
        if ($a instanceof \stdClass || $b instanceof \stdClass) {
            if (! $a instanceof \stdClass || ! $b instanceof \stdClass) {
                return false;
            }
            $av = get_object_vars($a);
            $bv = get_object_vars($b);
            if (count($av) !== count($bv)) {
                return false;
            }
            foreach ($av as $k => $v) {
                if (! array_key_exists($k, $bv) || ! self::deepEqual($v, $bv[$k])) {
                    return false;
                }
            }

            return true;
        }

        if (is_array($a) || is_array($b)) {
            if (! is_array($a) || ! is_array($b) || count($a) !== count($b)) {
                return false;
            }
            foreach (array_values($a) as $i => $v) {
                if (! self::deepEqual($v, array_values($b)[$i])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
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
