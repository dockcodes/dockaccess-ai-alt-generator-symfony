<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Event;

/**
 * A background generation gave up. `$reason` is stable (see Support\FailureReason and the core's
 * InvalidImageException reasons); `$message` is meant for logs, not for end users.
 */
final class AltTextFailedEvent
{
    /**
     * @param array<string, mixed> $context what was passed with the message, untouched
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $storage,
        public readonly string $reason,
        public readonly string $message,
        public readonly array $context,
    ) {}
}
