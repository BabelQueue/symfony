<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\DependencyInjection;

use BabelQueue\Symfony\Messenger\BabelQueueSerializer;
use BabelQueue\Symfony\Messenger\MessageRegistry;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Wires the URN registry and the Messenger serializer into the container.
 *
 * Exposes the public alias `babelqueue.messenger.serializer` for use as a
 * transport `serializer:` in messenger.yaml.
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
    }

    public function getAlias(): string
    {
        return 'babelqueue';
    }
}
