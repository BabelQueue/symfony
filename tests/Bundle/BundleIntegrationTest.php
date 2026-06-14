<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\Tests\Bundle;

use BabelQueue\Symfony\BabelQueueBundle;
use BabelQueue\Symfony\Messenger\BabelQueueSerializer;
use BabelQueue\Symfony\Messenger\MessageRegistry;
use BabelQueue\Symfony\Messenger\TracePropagationMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Boots a minimal Symfony kernel that registers only {@see BabelQueueBundle},
 * proving the bundle wires its services end-to-end through a real container
 * compile — not just the extension in isolation (see
 * {@see \BabelQueue\Symfony\Tests\DependencyInjection\BabelQueueExtensionTest}).
 *
 * The two public aliases must resolve to the concrete services, the trace
 * middleware must be available, and the `babelqueue` config (queue + URN
 * registry) must flow through to the serializer and registry.
 */
final class BundleIntegrationTest extends TestCase
{
    public function test_bundle_wires_its_public_services_through_a_real_container(): void
    {
        $kernel = new TestKernel([
            'queue' => 'orders',
            'messages' => ['urn:babel:orders:created' => 'App\Message\OrderCreated'],
        ]);
        $kernel->boot();
        $container = $kernel->getContainer();

        // The transport `serializer:` alias resolves to the canonical serializer.
        $this->assertTrue($container->has('babelqueue.messenger.serializer'));
        $this->assertInstanceOf(
            BabelQueueSerializer::class,
            $container->get('babelqueue.messenger.serializer'),
        );

        // The bus `middleware:` alias resolves to the trace-propagation middleware.
        $this->assertTrue($container->has('babelqueue.messenger.trace_middleware'));
        $this->assertInstanceOf(
            TracePropagationMiddleware::class,
            $container->get('babelqueue.messenger.trace_middleware'),
        );

        $kernel->shutdown();
    }

    public function test_configured_queue_and_message_registry_are_read(): void
    {
        $kernel = new TestKernel([
            'queue' => 'orders',
            'messages' => ['urn:babel:orders:created' => 'App\Message\OrderCreated'],
        ]);
        $kernel->boot();
        $container = $kernel->getContainer();

        /** @var BabelQueueSerializer $serializer */
        $serializer = $container->get('babelqueue.messenger.serializer');

        // The URN map from config flows into the registry the serializer decodes against.
        $registry = $this->registryOf($serializer);
        $this->assertSame('App\Message\OrderCreated', $registry->classFor('urn:babel:orders:created'));
        $this->assertNull($registry->classFor('urn:babel:unknown'));

        // The configured queue flows into the serializer's queue field.
        $this->assertSame('orders', $this->queueOf($serializer));

        $kernel->shutdown();
    }

    public function test_queue_defaults_to_default_when_config_is_empty(): void
    {
        $kernel = new TestKernel([]);
        $kernel->boot();

        /** @var BabelQueueSerializer $serializer */
        $serializer = $kernel->getContainer()->get('babelqueue.messenger.serializer');

        $this->assertSame('default', $this->queueOf($serializer));

        $kernel->shutdown();
    }

    private function registryOf(BabelQueueSerializer $serializer): MessageRegistry
    {
        $registry = (new \ReflectionProperty($serializer, 'registry'))->getValue($serializer);
        $this->assertInstanceOf(MessageRegistry::class, $registry);

        return $registry;
    }

    private function queueOf(BabelQueueSerializer $serializer): string
    {
        $queue = (new \ReflectionProperty($serializer, 'queue'))->getValue($serializer);
        $this->assertIsString($queue);

        return $queue;
    }
}

/**
 * A throwaway kernel that registers nothing but the BabelQueue bundle and feeds
 * it an inline `babelqueue` config — the smallest thing that exercises the real
 * bundle/extension/compiler path.
 */
final class TestKernel extends Kernel
{
    /**
     * @param  array<string, mixed>  $babelqueueConfig
     */
    public function __construct(private array $babelqueueConfig)
    {
        // A unique env per instance keeps each boot's compiled container isolated.
        parent::__construct('test_' . substr(md5(serialize($babelqueueConfig) . random_bytes(8)), 0, 12), true);
    }

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new BabelQueueBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        // The kernel's loader resolver includes a ClosureLoader, so we can wire the
        // extension config inline without shipping a YAML/PHP config file.
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('babelqueue', $this->babelqueueConfig);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/babelqueue_symfony_test/' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/babelqueue_symfony_test/' . $this->environment . '/log';
    }
}
