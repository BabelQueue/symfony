<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\DependencyInjection;

use BabelQueue\Symfony\Messenger\BabelQueueSerializer;
use BabelQueue\Symfony\Messenger\MessageRegistry;
use BabelQueue\Symfony\Messenger\TracePropagationMiddleware;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Wires the URN registry, the Messenger serializer and the trace-propagation
 * middleware into the container.
 *
 * Exposes the public aliases `babelqueue.messenger.serializer` (a transport
 * `serializer:`) and `babelqueue.messenger.trace_middleware` (a bus
 * `middleware:`) for use in messenger.yaml.
 */
final class BabelQueueExtension extends Extension
{
    /**
     * @param  array<int, array<string, mixed>>  $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{queue: string, messages: array<string, class-string>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setDefinition(
            MessageRegistry::class,
            new Definition(MessageRegistry::class, [$config['messages']]),
        );

        $container->setDefinition(
            BabelQueueSerializer::class,
            new Definition(BabelQueueSerializer::class, [
                new Reference(MessageRegistry::class),
                $config['queue'],
            ]),
        );

        $container->setAlias('babelqueue.messenger.serializer', BabelQueueSerializer::class)
            ->setPublic(true);

        $container->setDefinition(
            TracePropagationMiddleware::class,
            new Definition(TracePropagationMiddleware::class),
        );

        $container->setAlias('babelqueue.messenger.trace_middleware', TracePropagationMiddleware::class)
            ->setPublic(true);
    }

    public function getAlias(): string
    {
        return 'babelqueue';
    }
}
