<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Event;

/**
 * A background generation (GenerateAltText message) succeeded. Listen for it to store the alt
 * where it belongs:
 *
 *     #[AsEventListener]
 *     public function __invoke(AltTextGeneratedEvent $event): void
 *     {
 *         $this->products->find($event->context['product_id'])?->setImageAlt($event->alt);
 *     }
 *
 * The alt is plain text already; escape it where it is printed, as Twig does.
 */
final class AltTextGeneratedEvent
{
    /**
     * @param list<array{text: string, locale: string}> $alts every variant, the first one is `$alt`
     * @param array<string, mixed> $context what was passed with the message, untouched
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $storage,
        public readonly string $alt,
        public readonly string $locale,
        public readonly array $alts,
        public readonly string $imageUuid,
        public readonly string $setUuid,
        public readonly array $context,
    ) {}
}
