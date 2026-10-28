<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\DependencyInjection;

use Dock\AltGenerator\Symfony\MessageHandler\GenerateAltTextHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The storages a GenerateAltText message may name: the ids listed in `storages` and every service
 * tagged `flysystem.storage` (league/flysystem-bundle). A message cannot reach any other service.
 */
final class StoragesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (! $container->hasDefinition(GenerateAltTextHandler::class)) {
            return;
        }

        $ids = (array) $container->getParameter('dockaccess_alt_generator.storage_ids');
        $ids = array_merge($ids, array_keys($container->findTaggedServiceIds('flysystem.storage')));

        $references = [];

        foreach (array_unique(array_filter($ids, 'is_string')) as $id) {
            if ($container->has($id)) {
                $references[$id] = new Reference($id);
            }
        }

        $container->getDefinition(GenerateAltTextHandler::class)
            ->replaceArgument(2, ServiceLocatorTagPass::register($container, $references));
    }
}
