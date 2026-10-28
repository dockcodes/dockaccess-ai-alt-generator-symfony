<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Tests;

use Dock\AltGenerator\Symfony\Event\AltTextFailedEvent;
use Dock\AltGenerator\Symfony\Event\AltTextGeneratedEvent;
use Dock\AltGenerator\Symfony\Message\GenerateAltText;
use Dock\AltGenerator\Symfony\MessageHandler\GenerateAltTextHandler;
use Dock\AltGenerator\Symfony\Testing\FakeTransport;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class MessengerTest extends BundleTestCase
{
    /** @var list<object> */
    private array $events = [];

    public function test_a_generation_is_announced_with_the_context(): void
    {
        $this->bootWithStorage();

        $this->handler()(new GenerateAltText('chairs/1.png', 'test.storage', ['language' => 'en'], ['product_id' => 7]));

        $event = $this->events[0] ?? null;
        $this->assertInstanceOf(AltTextGeneratedEvent::class, $event);
        $this->assertSame('An image', $event->alt);
        $this->assertSame(['product_id' => 7], $event->context);
        $this->assertSame('test.storage', $event->storage);
        $this->assertSame([['text' => 'An image', 'locale' => 'en']], $event->alts);
    }

    public function test_a_spent_limit_is_not_retried_and_announced_once(): void
    {
        $this->bootWithStorage();
        $this->transport()->respond('/api/generate', FakeTransport::json(
            ['detail' => "You've reached your monthly limit."],
            429,
            ['X-Alt-Reason' => 'limit_reached', 'X-Alt-Daily-Limit' => '2000', 'X-Alt-Limit-Period' => 'month'],
        ));
        $message = new GenerateAltText('chairs/1.png', 'test.storage', [], ['product_id' => 7]);

        try {
            $this->handler()($message);
            $this->fail('No exception.');
        } catch (UnrecoverableMessageHandlingException $e) {
            $this->dispatch(new WorkerMessageFailedEvent(new Envelope($message), 'async', new HandlerFailedException(new Envelope($message), [$e])));
        }

        $failed = array_values(array_filter($this->events, static fn ($event) => $event instanceof AltTextFailedEvent));
        $this->assertCount(1, $failed);
        $this->assertSame('limit_reached', $failed[0]->reason);
        $this->assertSame(['product_id' => 7], $failed[0]->context);
    }

    public function test_the_per_minute_cap_goes_back_to_the_retry(): void
    {
        $this->bootWithStorage();
        $this->transport()->respond('/api/generate', FakeTransport::json(['detail' => 'Too many requests'], 429, ['X-Alt-Reason' => 'rate_limited', 'Retry-After' => '17']));

        try {
            $this->handler()(new GenerateAltText('chairs/1.png', 'test.storage'));
            $this->fail('No exception.');
        } catch (RecoverableMessageHandlingException $e) {
            if (method_exists($e, 'getRetryDelay')) {
                $this->assertSame(17000, $e->getRetryDelay());
            }
        }

        $this->assertSame([], $this->events);
    }

    public function test_a_retry_that_is_still_coming_is_not_a_failure(): void
    {
        $this->bootWithStorage();
        $event = new WorkerMessageFailedEvent(new Envelope(new GenerateAltText('chairs/1.png')), 'async', new \RuntimeException('Outage'));
        $event->setForRetry();

        $this->dispatch($event);

        $this->assertSame([], array_filter($this->events, static fn ($event) => $event instanceof AltTextFailedEvent));
    }

    public function test_only_known_storages_can_be_named(): void
    {
        $this->bootWithStorage();

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handler()(new GenerateAltText('chairs/1.png', 'kernel'));
    }

    private function bootWithStorage(): void
    {
        $container = $this->boot();
        $storage = $container->get('test.storage');
        \assert($storage instanceof FilesystemOperator);
        $storage->write('chairs/1.png', self::png());

        $dispatcher = $container->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);

        foreach ([AltTextGeneratedEvent::class, AltTextFailedEvent::class] as $name) {
            $dispatcher->addListener($name, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    private function handler(): GenerateAltTextHandler
    {
        $handler = $this->container()->get(GenerateAltTextHandler::class);
        \assert($handler instanceof GenerateAltTextHandler);

        return $handler;
    }

    private function dispatch(object $event): void
    {
        $dispatcher = $this->container()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->dispatch($event);
    }
}
