<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Support;

use Dock\AltGenerator\AltGenerator;
use Dock\AltGenerator\Connection\ConnectionClient;
use Dock\AltGenerator\Http\TransportInterface;
use Dock\AltGenerator\Image\ImagePolicy;
use Dock\AltGenerator\Symfony\DockAccessAltGeneratorBundle;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Factories of the container's services. A transport defined as the service
 * `Dock\AltGenerator\Http\TransportInterface` replaces cURL (tests, a platform client); it must
 * keep the guarantees listed in the core's README.
 *
 * @internal
 */
final class ServiceFactory
{
    /**
     * @param array<string, mixed> $settings
     */
    public static function generator(array $settings, ?TransportInterface $transport = null): AltGenerator
    {
        return new AltGenerator(ConfigFactory::make($settings, self::userAgent()), $transport);
    }

    /**
     * With the key when there is one (alt updates need it), without it for connecting.
     *
     * @param array<string, mixed> $settings
     */
    public static function connectionClient(array $settings, ?TransportInterface $transport = null): ConnectionClient
    {
        return new ConnectionClient(ConfigFactory::make($settings, self::userAgent()), $transport);
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function imagePolicy(array $settings): ImagePolicy
    {
        return ConfigFactory::make($settings, self::userAgent(), false)->imagePolicy();
    }

    public static function userAgent(): string
    {
        return 'symfony/'.Kernel::VERSION.' dockaccess-alt-symfony/'.DockAccessAltGeneratorBundle::VERSION;
    }
}
