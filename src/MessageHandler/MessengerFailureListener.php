<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\MessageHandler;

use Dock\AltGenerator\Symfony\Event\AltTextFailedEvent;
use Dock\AltGenerator\Symfony\Message\GenerateAltText;
use Dock\AltGenerator\Symfony\Support\FailureReason;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Turns the end of a GenerateAltText message - refused at once, or out of retries - into one
 * AltTextFailedEvent, with the reason of the generator's exception rather than Messenger's wrapper.
 */
final class MessengerFailureListener
{
    public function __construct(private readonly EventDispatcherInterface $events) {}

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        if (! $message instanceof GenerateAltText || $event->willRetry()) {
            return;
        }

        $cause = self::cause($event->getThrowable());

        $this->events->dispatch(new AltTextFailedEvent(
            $message->path,
            $message->storage,
            FailureReason::of($cause),
            $cause->getMessage(),
            $message->context,
        ));
    }

    /**
     * The innermost exception: Messenger wraps the handler's in HandlerFailedException, the handler
     * wraps the generator's in (Un)RecoverableMessageHandlingException.
     */
    private static function cause(Throwable $e): Throwable
    {
        while ($e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        return $e;
    }
}
