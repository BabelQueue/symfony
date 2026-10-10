<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Messenger;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Symfony\Contracts\PolyglotMessage;
use BabelQueue\Symfony\Messenger\Stamp\BabelEnvelopeStamp;
use BabelQueue\Symfony\Messenger\Stamp\BabelMessageIdStamp;
use BabelQueue\Symfony\Messenger\Stamp\BabelTraceStamp;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * A Symfony Messenger transport serializer that speaks the BabelQueue canonical
 * envelope, so Symfony services interoperate byte-for-byte with the PHP/Laravel,
 * Go, Python, ... SDKs. Encoding/decoding goes through the shared core codec
 * ({@see EnvelopeCodec} from babelqueue/php-sdk) — there is no second wire format.
 *
 * Wire it into config/packages/messenger.yaml:
 *
 *     framework:
 *       messenger:
 *         transports:
 *           babel:
 *             dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
 *             serializer: 'babelqueue.messenger.serializer'
 *
 * Routing stays Messenger's job: it maps the decoded message class to a handler
 * and runs it with its own worker/retry. On decode, Messenger's redelivery count
 * is bridged to/from the canonical top-level `attempts` field.
 */
final class BabelQueueSerializer implements SerializerInterface
{
    public function __construct(
        private MessageRegistry $registry,
        private string $queue = 'default',
    ) {
    }

    /**
     * @return array{body: string, headers: array<string, string>}
     */
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        if (! $message instanceof PolyglotMessage) {
            throw new LogicException(sprintf(
                'To send %s through a BabelQueue transport it must implement %s.',
                $message::class,
                PolyglotMessage::class,
            ));
        }

        $payload = EnvelopeCodec::fromJob($message, $this->queue);

        // Bridge Messenger's redelivery counter into the canonical attempts field.
        $attempts = $envelope->last(RedeliveryStamp::class)?->getRetryCount() ?? 0;
        $payload['attempts'] = $attempts;

        // Continue an existing trace when a trace stamp is present.
        $traceStamp = $envelope->last(BabelTraceStamp::class);
        if ($traceStamp instanceof BabelTraceStamp) {
            $payload['trace_id'] = $traceStamp->traceId;
        }

        // Forward what the typed message cannot express: unknown top-level / meta keys and the
        // original meta.id / meta.lang (message-envelope.md roundtrip rule). The freshly built
        // envelope still owns job, data, trace_id and attempts.
        $original = $envelope->last(BabelEnvelopeStamp::class);
        if ($original instanceof BabelEnvelopeStamp) {
            $payload = $this->restoreOriginal($payload, $original);
        }

        $finalMeta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $messageId = is_string($finalMeta['id'] ?? null) ? $finalMeta['id'] : '';
        // Same projection rule as bq-message-id: derive from the final body (restoreOriginal() may
        // have put a foreign producer's meta.lang back), never from this SDK's constant.
        $sourceLang = is_string($finalMeta['lang'] ?? null) ? $finalMeta['lang'] : EnvelopeCodec::SOURCE_LANG;

        return [
            'body' => EnvelopeCodec::encode($payload),
            // Mirror the canonical envelope onto the cross-broker `bq-` transport headers (the same
            // projection Kafka/SQS/Pulsar use), so a transport that maps serializer headers onto
            // native broker headers carries the contract metadata; the body stays authoritative.
            'headers' => [
                'Content-Type' => 'application/json',
                'bq-job' => is_string($payload['job'] ?? null) ? $payload['job'] : '',
                'bq-trace-id' => is_string($payload['trace_id'] ?? null) ? $payload['trace_id'] : '',
                'bq-message-id' => $messageId,
                'bq-schema-version' => (string) EnvelopeCodec::SCHEMA_VERSION,
                'bq-source-lang' => $sourceLang,
                'bq-attempts' => (string) $attempts,
            ],
        ];
    }

    /**
     * @param  array{body?: string, headers?: array<string, mixed>}  $encodedEnvelope
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        $data = EnvelopeCodec::decode((string) ($encodedEnvelope['body'] ?? ''));

        // The canonical field is "job"; "urn" is accepted as an inbound alias.
        $urn = EnvelopeCodec::urn($data);

        if ($urn === '') {
            throw new MessageDecodingFailedException('BabelQueue envelope has no URN ("job").');
        }

        // Contract: "Consumers MUST reject (or quarantine) versions they do not understand."
        // "meta.schema_version" is required: an absent (or non-integer) one is as unsupported as
        // a different number, matching the php-sdk core's EnvelopeValidator and the other SDKs.
        $rawMeta = $data['meta'] ?? null;
        $rawVersion = is_array($rawMeta) ? ($rawMeta['schema_version'] ?? null) : null;
        if ($rawVersion !== EnvelopeCodec::SCHEMA_VERSION) {
            throw new MessageDecodingFailedException(sprintf(
                'Unsupported BabelQueue meta.schema_version [%s] (supported: %d).',
                $rawVersion === null ? 'absent' : (is_scalar($rawVersion) ? (string) $rawVersion : get_debug_type($rawVersion)),
                EnvelopeCodec::SCHEMA_VERSION,
            ));
        }

        $class = $this->registry->classFor($urn);

        if ($class === null) {
            throw new MessageDecodingFailedException(sprintf(
                'No message class is mapped for URN [%s]. Add it to "babelqueue.messages".',
                $urn,
            ));
        }

        if (! is_a($class, PolyglotMessage::class, true)) {
            throw new MessageDecodingFailedException(sprintf(
                'Message class [%s] mapped for URN [%s] must implement %s.',
                $class,
                $urn,
                PolyglotMessage::class,
            ));
        }

        /** @var array<string, mixed> $payloadData */
        $payloadData = (array) ($data['data'] ?? []);
        $message = $class::fromBabelPayload($payloadData);

        $stamps = [];

        $rawAttempts = $data['attempts'] ?? 0;
        $attempts = is_numeric($rawAttempts) ? (int) $rawAttempts : 0;
        if ($attempts > 0) {
            $stamps[] = new RedeliveryStamp($attempts);
        }

        $rawTraceId = $data['trace_id'] ?? '';
        $traceId = is_string($rawTraceId) ? $rawTraceId : '';
        if ($traceId !== '') {
            $stamps[] = new BabelTraceStamp($traceId);
        }

        // Surface the canonical meta.id so consume-side middleware (idempotency)
        // can dedupe a redelivery on the stable per-message identity.
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $rawMessageId = $meta['id'] ?? '';
        $messageId = is_string($rawMessageId) ? $rawMessageId : '';
        if ($messageId !== '') {
            $stamps[] = new BabelMessageIdStamp($messageId);
        }

        $original = $this->originalEnvelopeStamp((string) ($encodedEnvelope['body'] ?? ''));
        if ($original !== null) {
            $stamps[] = $original;
        }

        return new Envelope($message, $stamps);
    }

    /**
     * Capture the unknown top-level keys and the original meta block of an inbound body.
     */
    private function originalEnvelopeStamp(string $body): ?BabelEnvelopeStamp
    {
        // Second parse on purpose: EnvelopeCodec::decode() returns assoc arrays, where an empty
        // JSON object "{}" is indistinguishable from "[]". Decoding to stdClass keeps "{}" an
        // object so the retry re-emits the exact shape the producer sent.
        $raw = json_decode($body);
        if (! $raw instanceof \stdClass) {
            return null;
        }

        $owned = ['job', 'urn', 'data', 'trace_id', 'attempts', 'meta'];
        $topLevel = new \stdClass();
        foreach (get_object_vars($raw) as $key => $value) {
            if (! in_array((string) $key, $owned, true) && ! in_array((string) $key, EnvelopeCodec::FORBIDDEN_TOP_LEVEL_KEYS, true)) {
                $topLevel->{$key} = $value;
            }
        }

        $meta = new \stdClass();
        if (($raw->meta ?? null) instanceof \stdClass) {
            foreach (get_object_vars($raw->meta) as $key => $value) {
                if (! in_array((string) $key, EnvelopeCodec::FORBIDDEN_META_KEYS, true)) {
                    $meta->{$key} = $value;
                }
            }
        }

        return new BabelEnvelopeStamp(
            json_encode($topLevel, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function restoreOriginal(array $payload, BabelEnvelopeStamp $original): array
    {
        foreach ($original->topLevel() as $key => $value) {
            if (! array_key_exists($key, $payload)) {
                $payload[$key] = $value;
            }
        }

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        foreach ($original->meta() as $key => $value) {
            // The wire version is always ours; everything else the producer set is kept.
            if ($key !== 'schema_version') {
                $meta[$key] = $value;
            }
        }
        $payload['meta'] = $meta;

        return $payload;
    }
}
