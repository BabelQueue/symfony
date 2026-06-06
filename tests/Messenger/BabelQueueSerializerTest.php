<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Tests\Messenger;

use BabelQueue\Symfony\Contracts\PolyglotMessage;
use BabelQueue\Symfony\Messenger\BabelQueueSerializer;
use BabelQueue\Symfony\Messenger\MessageRegistry;
use BabelQueue\Symfony\Messenger\Stamp\BabelTraceStamp;
use LogicException;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

final class BabelQueueSerializerTest extends TestCase
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private function serializer(): BabelQueueSerializer
    {
        return new BabelQueueSerializer(
            new MessageRegistry(['urn:babel:orders:created' => OrderCreatedStub::class]),
            'orders',
        );
    }

    public function test_encode_produces_the_canonical_envelope(): void
    {
        $encoded = $this->serializer()->encode(new Envelope(new OrderCreatedStub(1042)));

        $this->assertArrayHasKey('body', $encoded);
        $this->assertSame('application/json', $encoded['headers']['Content-Type']);
        $this->assertSame('urn:babel:orders:created', $encoded['headers']['X-Babel-Urn']);

        $payload = json_decode($encoded['body'], true);
        $this->assertSame(['job', 'trace_id', 'data', 'meta', 'attempts'], array_keys($payload));
        $this->assertSame('urn:babel:orders:created', $payload['job']);
        $this->assertSame(['order_id' => 1042], $payload['data']);
        $this->assertSame('orders', $payload['meta']['queue']);
        $this->assertSame('php', $payload['meta']['lang']);
        $this->assertSame(1, $payload['meta']['schema_version']);
        $this->assertSame(0, $payload['attempts']);
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $payload['trace_id']);
    }

    public function test_round_trips_back_to_the_message(): void
    {
        $serializer = $this->serializer();

        $encoded = $serializer->encode(new Envelope(new OrderCreatedStub(7)));
        $decoded = $serializer->decode($encoded);

        $message = $decoded->getMessage();
        $this->assertInstanceOf(OrderCreatedStub::class, $message);
        $this->assertSame(7, $message->orderId);
    }

    public function test_redelivery_count_bridges_to_attempts(): void
    {
        $serializer = $this->serializer();

        // encode: a RedeliveryStamp(2) becomes attempts=2 on the wire.
        $encoded = $serializer->encode(
            new Envelope(new OrderCreatedStub(1), [new RedeliveryStamp(2)]),
        );
        $this->assertSame(2, json_decode($encoded['body'], true)['attempts']);

        // decode: attempts=3 on the wire becomes a RedeliveryStamp(3).
        $wire = json_decode($encoded['body'], true);
        $wire['attempts'] = 3;
        $encoded['body'] = json_encode($wire);
        $stamp = $serializer->decode($encoded)->last(RedeliveryStamp::class);
        $this->assertInstanceOf(RedeliveryStamp::class, $stamp);
        $this->assertSame(3, $stamp->getRetryCount());
    }

    public function test_trace_id_is_exposed_on_decode_and_honoured_on_encode(): void
    {
        $serializer = $this->serializer();

        // decode attaches a BabelTraceStamp carrying the inbound trace id.
        $body = json_encode([
            'job' => 'urn:babel:orders:created',
            'trace_id' => 'trace-abc',
            'data' => ['order_id' => 5],
            'meta' => ['id' => 'm1'],
            'attempts' => 0,
        ]);
        $decoded = $serializer->decode(['body' => $body, 'headers' => []]);
        $stamp = $decoded->last(BabelTraceStamp::class);
        $this->assertInstanceOf(BabelTraceStamp::class, $stamp);
        $this->assertSame('trace-abc', $stamp->traceId);

        // encode honours the stamp (trace continuation) instead of minting a new id.
        $reEncoded = $serializer->encode(
            new Envelope(new OrderCreatedStub(5), [new BabelTraceStamp('trace-abc')]),
        );
        $this->assertSame('trace-abc', json_decode($reEncoded['body'], true)['trace_id']);
    }

    public function test_unknown_urn_fails_decoding(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        $this->serializer()->decode([
            'body' => json_encode(['job' => 'urn:babel:unknown', 'data' => [], 'meta' => []]),
        ]);
    }

    public function test_missing_urn_fails_decoding(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        $this->serializer()->decode(['body' => json_encode(['data' => [], 'meta' => []])]);
    }

    public function test_non_polyglot_message_cannot_be_encoded(): void
    {
        $this->expectException(LogicException::class);

        $this->serializer()->encode(new Envelope(new stdClass()));
    }
}

final class OrderCreatedStub implements PolyglotMessage
{
    public function __construct(public int $orderId = 0)
    {
    }

    public function getBabelUrn(): string
    {
        return 'urn:babel:orders:created';
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return ['order_id' => $this->orderId];
    }

    /** @param array<string, mixed> $data */
    public static function fromBabelPayload(array $data): static
    {
        return new self((int) ($data['order_id'] ?? 0));
    }
}
