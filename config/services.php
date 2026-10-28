<?php

declare(strict_types=1);

use Dock\AltGenerator\AltGenerator;
use Dock\AltGenerator\Connection\ConnectionClient;
use Dock\AltGenerator\Http\TransportInterface;
use Dock\AltGenerator\Image\ImagePolicy;
use Dock\AltGenerator\Symfony\AltText;
use Dock\AltGenerator\Symfony\Command\ConnectCommand;
use Dock\AltGenerator\Symfony\Command\DisconnectCommand;
use Dock\AltGenerator\Symfony\Command\GenerateCommand;
use Dock\AltGenerator\Symfony\Command\StatusCommand;
use Dock\AltGenerator\Symfony\ConnectionGuard;
use Dock\AltGenerator\Symfony\Message\GenerateAltText;
use Dock\AltGenerator\Symfony\MessageHandler\GenerateAltTextHandler;
use Dock\AltGenerator\Symfony\MessageHandler\MessengerFailureListener;
use Dock\AltGenerator\Symfony\Support\ServiceFactory;
use Dock\AltGenerator\Symfony\Validator\GenuineImageValidator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $settings = param('dockaccess_alt_generator.settings');
    $transport = service(TransportInterface::class)->nullOnInvalid();

    // Built on first use (AltText gets a closure), so an application that is not connected yet still boots.
    $services->set(AltGenerator::class)
        ->factory([ServiceFactory::class, 'generator'])
        ->args([$settings, $transport]);

    $services->set(ConnectionClient::class)
        ->factory([ServiceFactory::class, 'connectionClient'])
        ->args([$settings, $transport]);

    $services->set(ConnectionGuard::class)
        ->args([
            service(ConnectionClient::class),
            service('dockaccess_alt_generator.cache'),
            $settings,
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'dockaccess_alt']);

    $services->set(AltText::class)
        ->args([
            service_closure(AltGenerator::class),
            service(ConnectionGuard::class),
            inline_service(ImagePolicy::class)->factory([ServiceFactory::class, 'imagePolicy'])->args([$settings]),
            $settings,
            param('kernel.default_locale'),
            service('request_stack')->nullOnInvalid(),
        ])
        ->public();

    $services->alias('dockaccess_alt_generator', AltText::class)->public();

    $services->set(ConnectCommand::class)
        ->args([service(ConnectionClient::class), service(ConnectionGuard::class), param('kernel.project_dir'), service('router.request_context')->nullOnInvalid()])
        ->tag('console.command');

    $services->set(StatusCommand::class)
        ->args([service(AltText::class), $settings])
        ->tag('console.command');

    $services->set(DisconnectCommand::class)
        ->args([service(ConnectionClient::class), service(ConnectionGuard::class), $settings, param('kernel.project_dir')])
        ->tag('console.command');

    $services->set(GenerateCommand::class)
        ->args([service(AltText::class)])
        ->tag('console.command');

    $services->set(GenerateAltTextHandler::class)
        // The storages argument is filled by StoragesPass.
        ->args([service(AltText::class), service('event_dispatcher'), service_locator([])])
        ->tag('messenger.message_handler', ['handles' => GenerateAltText::class]);

    $services->set(MessengerFailureListener::class)
        ->args([service('event_dispatcher')])
        ->tag('kernel.event_listener', ['event' => WorkerMessageFailedEvent::class]);

    $services->set(GenuineImageValidator::class)
        ->args([service(AltText::class)])
        ->tag('validator.constraint_validator');
};
