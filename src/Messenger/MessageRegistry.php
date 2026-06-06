<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Messenger;

/**
 * Maps an inbound message URN onto the PHP message class that represents it.
 *
 * Only the consume side needs this (decode): the produce side reads the URN
 * straight off the message via getBabelUrn(). Populated from the bundle config
 * (`babelqueue.messages`).
 */
final class MessageRegistry
{
    /**
     * @param  array<string, class-string>  $map  urn => message class
     */
    public function __construct(private array $map = [])
    {
    }

    /**
     * @return class-string|null
     */
    public function classFor(string $urn): ?string
    {
        return $this->map[$urn] ?? null;
    }

    /**
     * @return array<string, class-string>
     */
    public function all(): array
    {
        return $this->map;
    }
}
