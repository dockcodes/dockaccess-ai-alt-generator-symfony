<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\DependencyInjection;

use Dock\AltGenerator\Symfony\MessageHandler\GenerateAltTextHandler;
use Dock\AltGenerator\Symfony\MessageHandler\MessengerFailureListener;
use Dock\AltGenerator\Symfony\Validator\GenuineImageValidator;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\ConstraintValidator;

final class DockAccessAltGeneratorExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('dockaccess_alt_generator.settings', $config);
        $container->setParameter('dockaccess_alt_generator.storage_ids', $config['storages']);

        (new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config')))->load('services.php');

        $container->setAlias('dockaccess_alt_generator.cache', $config['verify']['cache_pool']);

        // Optional integrations: only when the application has the component.
        if (! interface_exists(MessageBusInterface::class)) {
            $container->removeDefinition(GenerateAltTextHandler::class);
            $container->removeDefinition(MessengerFailureListener::class);
        }

        if (! class_exists(ConstraintValidator::class)) {
            $container->removeDefinition(GenuineImageValidator::class);
        }
    }

    public function getAlias(): string
    {
        return 'dockaccess_alt_generator';
    }
}
