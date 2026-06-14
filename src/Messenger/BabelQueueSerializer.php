<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Messenger;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Symfony\Contracts\PolyglotMessage;
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
        $payload['attempts'] = $envelope->last(RedeliveryStamp::class)?->getRetryCount() ?? 0;

        // Continue an existing trace when a trace stamp is present.
        $traceStamp = $envelope->last(BabelTraceStamp::class);
        if ($traceStamp instanceof BabelTraceStamp) {
            $payload['trace_id'] = $traceStamp->traceId;
        }

        $messageId = is_string($payload['meta']['id'] ?? null) ? $payload['meta']['id'] : '';

        return [
            'body' => EnvelopeCodec::encode($payload),
            // Mirror the canonical envelope onto the cross-broker `bq-` transport headers (the same
            // projection Kafka/SQS/Pulsar use), so a transport that maps serializer headers onto
            // native broker headers carries the contract metadata; the body stays authoritative.
            'headers' => [
                'Content-Type' => 'application/json',
                'bq-job' => $payload['job'],
                'bq-trace-id' => $payload['trace_id'],
                'bq-message-id' => $messageId,
                'bq-schema-version' => (string) EnvelopeCodec::SCHEMA_VERSION,
                'bq-source-lang' => EnvelopeCodec::SOURCE_LANG,
                'bq-attempts' => (string) $payload['attempts'],
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

        return new Envelope($message, $stamps);
    }
}
