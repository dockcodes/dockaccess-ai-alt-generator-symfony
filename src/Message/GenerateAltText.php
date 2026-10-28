<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Message;

/**
 * Generates alt text in the background; the result comes as AltTextGeneratedEvent (or
 * AltTextFailedEvent):
 *
 *     $bus->dispatch(new GenerateAltText('products/42.jpg', 'default.storage', ['context' => $name], ['product_id' => 42]));
 *
 * Route it to an async transport in config/packages/messenger.yaml.
 */
final class GenerateAltText
{
    /**
     * @param string $path a path on `$storage`, or an absolute path inside `allowed_directories` when `$storage` is null
     * @param string|null $storage the service id of a Flysystem storage (league/flysystem-bundle), e.g. "default.storage"
     * @param array<string, mixed> $options language, tone, context, variants, set_ref
     * @param array<string, mixed> $context anything the listener needs to find the record (an id, not an entity)
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $storage = null,
        public readonly array $options = [],
        public readonly array $context = [],
    ) {}
}
