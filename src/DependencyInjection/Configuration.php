<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Config schema for the `babelqueue` key:
 *
 *     babelqueue:
 *         queue: 'default'                 # logical queue name written to meta.queue
 *         messages:                        # urn => message class (decode/consume side)
 *             'urn:babel:orders:created': 'App\Message\OrderCreated'
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('babelqueue');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('queue')
                    ->info('Logical queue name written to the envelope meta.queue.')
                    ->defaultValue('default')
                ->end()
                ->arrayNode('messages')
                    ->info('Map of message URN => message class, used to decode inbound messages.')
                    ->useAttributeAsKey('urn')
                    ->scalarPrototype()->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
