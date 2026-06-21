<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Tests\DependencyInjection;

use BabelQueue\Idempotency\IdempotencyStore;
use BabelQueue\Idempotency\InMemoryStore;
use BabelQueue\Symfony\DependencyInjection\BabelQueueExtension;
use BabelQueue\Symfony\Messenger\BabelQueueSerializer;
use BabelQueue\Symfony\Messenger\IdempotencyMiddleware;
use BabelQueue\Symfony\Messenger\MessageRegistry;
use BabelQueue\Symfony\Messenger\TracePropagationMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The bundle's DI wiring: the extension registers the registry, serializer and
 * trace middleware, and exposes the two public aliases used in messenger.yaml.
 */
final class BabelQueueExtensionTest extends TestCase
{
    public function test_it_registers_services_and_public_aliases(): void
    {
        $container = new ContainerBuilder();

        (new BabelQueueExtension())->load([[
            'queue' => 'orders',
            'messages' => ['urn:babel:orders:created' => 'App\Message\OrderCreated'],
        ]], $container);

        $this->assertTrue($container->hasDefinition(MessageRegistry::class));
        $this->assertTrue($container->hasDefinition(BabelQueueSerializer::class));
        $this->assertTrue($container->hasDefinition(TracePropagationMiddleware::class));

        foreach (['babelqueue.messenger.serializer', 'babelqueue.messenger.trace_middleware'] as $alias) {
            $this->assertTrue($container->hasAlias($alias), "missing alias {$alias}");
            $this->assertTrue($container->getAlias($alias)->isPublic(), "alias {$alias} must be public");
        }

        // The configured queue flows into the serializer's constructor.
        $this->assertSame('orders', $container->getDefinition(BabelQueueSerializer::class)->getArgument(1));

        // The URN map flows into the registry.
        $this->assertSame(
            ['urn:babel:orders:created' => 'App\Message\OrderCreated'],
            $container->getDefinition(MessageRegistry::class)->getArgument(0),
        );
    }

    public function test_queue_defaults_to_default_when_omitted(): void
    {
        $container = new ContainerBuilder();

        (new BabelQueueExtension())->load([[]], $container);

        $this->assertSame('default', $container->getDefinition(BabelQueueSerializer::class)->getArgument(1));
    }

    public function test_extension_alias(): void
    {
        $this->assertSame('babelqueue', (new BabelQueueExtension())->getAlias());
    }

    public function test_idempotency_is_off_by_default_and_registers_no_middleware(): void
    {
        $container = new ContainerBuilder();

        (new BabelQueueExtension())->load([[]], $container);

        $this->assertFalse($container->hasDefinition(IdempotencyMiddleware::class));
        $this->assertFalse($container->hasAlias('babelqueue.messenger.idempotency_middleware'));
        // The unrelated services are still wired (the disabled path is unchanged).
        $this->assertTrue($container->hasDefinition(BabelQueueSerializer::class));
        $this->assertTrue($container->hasDefinition(TracePropagationMiddleware::class));
    }

    public function test_enabling_idempotency_wires_the_middleware_with_a_default_in_memory_store(): void
    {
        $container = new ContainerBuilder();

        (new BabelQueueExtension())->load([[
            'idempotency' => ['enabled' => true],
        ]], $container);

        // The middleware is registered behind a public alias for the bus.
        $this->assertTrue($container->hasDefinition(IdempotencyMiddleware::class));
        $this->assertTrue($container->hasAlias('babelqueue.messenger.idempotency_middleware'));
        $this->assertTrue($container->getAlias('babelqueue.messenger.idempotency_middleware')->isPublic());

        // With no store configured, a bundled in-memory store backs it.
        $this->assertTrue($container->hasDefinition(InMemoryStore::class));
        $this->assertSame(InMemoryStore::class, (string) $container->getAlias(IdempotencyStore::class));

        // The middleware's first arg is a reference to the resolved store, second is the TTL.
        $middleware = $container->getDefinition(IdempotencyMiddleware::class);
        $this->assertSame(IdempotencyStore::class, (string) $middleware->getArgument(0));
        $this->assertSame(3600, $middleware->getArgument(1));
    }

    public function test_enabling_idempotency_uses_a_configured_store_service_and_ttl(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.my_store', new Definition(InMemoryStore::class));

        (new BabelQueueExtension())->load([[
            'idempotency' => [
                'enabled' => true,
                'store' => 'app.my_store',
                'ttl' => 120,
            ],
        ]], $container);

        $middleware = $container->getDefinition(IdempotencyMiddleware::class);
        $this->assertSame('app.my_store', (string) $middleware->getArgument(0));
        $this->assertSame(120, $middleware->getArgument(1));

        // No bundled in-memory store is registered when a service id is supplied.
        $this->assertFalse($container->hasDefinition(InMemoryStore::class));
        $this->assertFalse($container->hasAlias(IdempotencyStore::class));
    }
}
