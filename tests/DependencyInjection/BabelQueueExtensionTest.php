<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Tests\DependencyInjection;

use BabelQueue\Symfony\DependencyInjection\BabelQueueExtension;
use BabelQueue\Symfony\Messenger\BabelQueueSerializer;
use BabelQueue\Symfony\Messenger\MessageRegistry;
use BabelQueue\Symfony\Messenger\TracePropagationMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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
}
