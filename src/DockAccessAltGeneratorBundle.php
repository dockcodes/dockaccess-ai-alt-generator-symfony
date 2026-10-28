<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony;

use Dock\AltGenerator\Symfony\DependencyInjection\DockAccessAltGeneratorExtension;
use Dock\AltGenerator\Symfony\DependencyInjection\StoragesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class DockAccessAltGeneratorBundle extends Bundle
{
    public const VERSION = '1.0.0';

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new StoragesPass());
    }

    public function getContainerExtension(): ExtensionInterface
    {
        if (! $this->extension instanceof ExtensionInterface) {
            $this->extension = new DockAccessAltGeneratorExtension();
        }

        return $this->extension;
    }

    /**
     * The bundle root, so `translations/` is found next to `src/`.
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
