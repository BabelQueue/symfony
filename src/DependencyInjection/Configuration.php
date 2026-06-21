<?php

declare(strict_types=1);

namespace BabelQueue\Symfony\DependencyInjection;

use BabelQueue\Idempotency\ClaimingDispatch;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Config schema for the `babelqueue` key:
 *
 *     babelqueue:
 *         queue: 'default'                 # logical queue name written to meta.queue
 *         messages:                        # urn => message class (decode/consume side)
 *             'urn:babel:orders:created': 'App\Message\OrderCreated'
 *         idempotency:
 *             enabled: false               # opt-in; off => behavior unchanged
 *             store: ~                      # service id of a BabelQueue\Idempotency\IdempotencyStore;
 *                                           # null => a bundled in-memory store (tests / single process)
 *             ttl: 3600                     # in-flight claim TTL (s); only used by a ClaimingStore
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
                ->arrayNode('idempotency')
                    ->info('Deduplicate redelivered BabelQueue messages on meta.id (ADR-0022). Opt-in.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Register the idempotency middleware. Disabled by default — behavior unchanged when off.')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('store')
                            ->info('Service id of a BabelQueue\Idempotency\IdempotencyStore (or ClaimingStore). Null uses a bundled in-memory store — single-process / tests only.')
                            ->defaultNull()
                        ->end()
                        ->integerNode('ttl')
                            ->info('In-flight claim TTL in seconds (the crash backstop). Only honoured when the store implements ClaimingStore.')
                            ->defaultValue(ClaimingDispatch::DEFAULT_TTL)
                            ->min(1)
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
