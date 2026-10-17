<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Tests\Messenger;

use BabelQueue\Symfony\Contracts\PolyglotMessage;
use BabelQueue\Symfony\Messenger\BabelQueueSerializer;
use BabelQueue\Symfony\Messenger\MessageRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;

/**
 * ADR-0031: a consumer that re-emits a message MUST keep the keys it does not know.
 *
 * A payload carrying an unknown top-level key and unknown `meta` keys (scalar, nested and an
 * empty object `{}`) is decoded and re-encoded the way Messenger does on a retry
 * (RedeliveryStamp) and when it moves the message to the failure transport.
 */
final class UnknownKeyPassthroughTest extends TestCase
{
    private const BODY = '{"job":"urn:acme:orders:created","trace_id":"trace-1","data":{"order_id":7},'
        . '"meta":{"id":"m1","queue":"orders","lang":"go","schema_version":1,"created_at":1700000000,'
        . '"vendor_flag":true,"ext":{"acme":{"region":"eu"}},"empty_meta":{}},'
        . '"x_top":{"k":[1,2]},"x_empty":{},"attempts":1}';

    private function serializer(): BabelQueueSerializer
    {
        return new BabelQueueSerializer(
            new MessageRegistry(['urn:acme:orders:created' => PassthroughOrderCreated::class]),
            'orders',
        );
    }

    public function test_unknown_keys_survive_a_retry(): void
    {
        $decoded = $this->serializer()->decode(['body' => self::BODY]);
        $retry = $decoded->with(new RedeliveryStamp(2));

        $out = $this->serializer()->encode($retry)['body'];

        $this->assertPreserved($out);
        self::assertSame(2, self::parse($out)->attempts);
    }

    public function test_unknown_keys_survive_the_move_to_the_failure_transport(): void
    {
        $decoded = $this->serializer()->decode(['body' => self::BODY]);
        $failed = $decoded->with(
            new RedeliveryStamp(3),
            new SentToFailureTransportStamp('async'),
        );

        $out = $this->serializer()->encode($failed)['body'];

        $this->assertPreserved($out);
    }

    private function assertPreserved(string $out): void
    {
        $in = self::parse(self::BODY);
        $got = self::parse($out);

        self::assertEquals($in->meta->ext, $got->meta->ext, 'meta.ext');
        self::assertTrue($got->meta->vendor_flag, 'meta.vendor_flag');
        self::assertEquals($in->x_top, $got->x_top, 'x_top');
        self::assertSame('m1', $got->meta->id, 'meta.id');
        self::assertSame('go', $got->meta->lang, 'meta.lang');
        self::assertSame('trace-1', $got->trace_id);
        self::assertStringContainsString('"x_empty":{}', $out, 'an empty object stays {}');
        self::assertStringContainsString('"empty_meta":{}', $out, 'an empty meta object stays {}');
    }

    private static function parse(string $json): \stdClass
    {
        /** @var \stdClass */
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }
}

final class PassthroughOrderCreated implements PolyglotMessage
{
    public function __construct(public int $orderId = 0)
    {
    }

    public function getBabelUrn(): string
    {
        return 'urn:acme:orders:created';
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
