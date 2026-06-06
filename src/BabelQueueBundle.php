<?php

declare(strict_types=1);

namespace BabelQueue\Symfony;

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
}
