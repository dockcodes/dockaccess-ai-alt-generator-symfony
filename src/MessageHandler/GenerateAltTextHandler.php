<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\MessageHandler;

use Dock\AltGenerator\Exception\InvalidImageException;
use Dock\AltGenerator\Exception\RateLimitedException;
use Dock\AltGenerator\Result\Alt;
use Dock\AltGenerator\Symfony\AltText;
use Dock\AltGenerator\Symfony\Event\AltTextGeneratedEvent;
use Dock\AltGenerator\Symfony\Message\GenerateAltText;
use Dock\AltGenerator\Symfony\Support\FailureReason;
use League\Flysystem\FilesystemOperator;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Handles GenerateAltText. Only what can succeed later goes back to Messenger's retry: the per
 * minute cap (after the delay the generator asks for, on Symfony 7.2+) and outages. A spent limit,
 * a refused image or a disconnected application are unrecoverable - retrying them would only
 * spend time. AltTextFailedEvent is dispatched once Messenger gives up (MessengerFailureListener).
 */
final class GenerateAltTextHandler
{
    public function __construct(
        private readonly AltText $altText,
        private readonly EventDispatcherInterface $events,
        private readonly ContainerInterface $storages,
    ) {}

    public function __invoke(GenerateAltText $message): void
    {
        try {
            $result = $message->storage === null
                ? $this->altText->generate($message->path, $message->options)
                : $this->altText->generateFromFilesystem($this->storage($message->storage), $message->path, $message->options);
        } catch (Throwable $e) {
            if (FailureReason::isTemporary($e)) {
                throw self::recoverable($e);
            }

            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }

        $this->events->dispatch(new AltTextGeneratedEvent(
            $message->path,
            $message->storage,
            $result->alt()->text(),
            $result->alt()->locale(),
            array_values(array_map(static fn (Alt $alt) => ['text' => $alt->text(), 'locale' => $alt->locale()], $result->alts())),
            $result->imageUuid(),
            $result->setUuid(),
            $message->context,
        ));
    }

    private function storage(string $id): FilesystemOperator
    {
        $storage = $this->storages->has($id) ? $this->storages->get($id) : null;

        if (! $storage instanceof FilesystemOperator) {
            throw new InvalidImageException(InvalidImageException::NOT_ALLOWED, sprintf('"%s" is not a storage known to the bundle (dockaccess_alt_generator.storages).', $id));
        }

        return $storage;
    }

    private static function recoverable(Throwable $e): RecoverableMessageHandlingException
    {
        $delay = $e instanceof RateLimitedException ? $e->getRetryAfter() * 1000 : null;

        // The delay argument exists since Symfony 7.2; before that the retry strategy decides.
        if ($delay !== null && (new \ReflectionMethod(RecoverableMessageHandlingException::class, '__construct'))->getNumberOfParameters() >= 4) {
            return new RecoverableMessageHandlingException($e->getMessage(), 0, $e, $delay);
        }

        return new RecoverableMessageHandlingException($e->getMessage(), 0, $e);
    }
}
