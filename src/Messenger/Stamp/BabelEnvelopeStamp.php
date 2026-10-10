<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the parts of the inbound BabelQueue envelope that the typed message cannot
 * express, so a re-encode (a Messenger retry, a failure-transport hop) forwards them
 * unchanged — "consumers MUST ignore unknown keys" and "unknown keys MUST NOT be dropped,
 * renamed or retyped" (message-envelope.md §4/§8, GR-5).
 *
 * It holds the unknown top-level keys and the original `meta` block (so the stable
 * `meta.id`, `meta.lang`, `created_at` and any unknown meta key survive), each as a JSON
 * object string. JSON — rather than PHP arrays — keeps `{}` an object and stays safe to
 * PHP-serialize when Messenger persists the stamp to a failure transport. Forbidden keys
 * (§10) are never stored. The serializer attaches it on decode and reads it on encode;
 * nothing about the frozen wire envelope changes.
 */
final class BabelEnvelopeStamp implements StampInterface
{
    public function __construct(
        public readonly string $topLevelJson = '{}',
        public readonly string $metaJson = '{}',
    ) {
    }

    /**
     * Unknown top-level keys, as an associative array (nested objects stay `stdClass`).
     *
     * @return array<string, mixed>
     */
    public function topLevel(): array
    {
        return self::toArray($this->topLevelJson);
    }

    /**
     * The original `meta` members, as an associative array (nested objects stay `stdClass`).
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return self::toArray($this->metaJson);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(string $json): array
    {
        $decoded = json_decode($json);

        return $decoded instanceof \stdClass ? get_object_vars($decoded) : [];
    }
}
