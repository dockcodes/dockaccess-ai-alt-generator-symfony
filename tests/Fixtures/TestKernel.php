<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Tests\Fixtures;

use Dock\AltGenerator\Http\TransportInterface;
use Dock\AltGenerator\Symfony\DockAccessAltGeneratorBundle;
use Dock\AltGenerator\Symfony\Testing\FakeTransport;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $bundleConfig
     */
    public function __construct(private readonly string $root, private readonly array $bundleConfig = [])
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DockAccessAltGeneratorBundle();
    }

    public function getProjectDir(): string
    {
        return $this->root;
    }

    public function getCacheDir(): string
    {
        return $this->root.'/var/cache';
    }

    public function getLogDir(): string
    {
        return $this->root.'/var/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
            'default_locale' => 'en',
            'router' => ['default_uri' => 'https://shop.example.com', 'utf8' => true],
            'cache' => ['app' => 'cache.adapter.array'],
            'validation' => ['enabled' => true],
            'translator' => ['enabled' => true, 'fallbacks' => ['en']],
            'messenger' => ['enabled' => true],
            'php_errors' => ['log' => false],
        ]);

        $container->extension('dockaccess_alt_generator', $this->bundleConfig + [
            'account_key' => 'AccountKey0123456789abcdef',
            'site_token' => 'SiteToken0123456789',
            'connection_secret' => 'a1b2c3d4e5f60718293a4b5c6d7e8f901a2b3c4d5e6f7081',
            'allowed_directories' => [$this->root.'/images'],
        ]);

        $services = $container->services();

        $services->set('logger', NullLogger::class);

        // No test talks to dockaccess.org.
        $services->set(TransportInterface::class, FakeTransport::class)
            ->factory([FakeTransport::class, 'alts'])
            ->args(['An image'])
            ->public();

        $services->set('test.storage', Filesystem::class)
            ->args([inline_service(InMemoryFilesystemAdapter::class)])
            ->tag('flysystem.storage')
            ->public();
    }
}
