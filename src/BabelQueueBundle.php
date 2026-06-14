<?php

declare(strict_types=1);

namespace BabelQueue\Symfony;

use BabelQueue\Symfony\DependencyInjection\BabelQueueExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Registers the BabelQueue Symfony integration: the Messenger serializer service
 * `babelqueue.messenger.serializer`, the trace-propagation middleware
 * `babelqueue.messenger.trace_middleware`, and the URN → message-class registry,
 * all configured under the `babelqueue` config key.
 *
 * Enable it in config/bundles.php:
 *
 *     BabelQueue\Symfony\BabelQueueBundle::class => ['all' => true],
 */
final class BabelQueueBundle extends Bundle
{
    /**
     * Return the extension explicitly so the `babelqueue` config key (rather than
     * the auto-derived `babel_queue`) is the one the kernel registers.
     */
    public function getContainerExtension(): BabelQueueExtension
    {
        return new BabelQueueExtension();
    }
}
