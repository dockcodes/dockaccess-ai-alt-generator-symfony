<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Tests;

use Dock\AltGenerator\Http\TransportInterface;
use Dock\AltGenerator\Symfony\Testing\FakeTransport;
use Dock\AltGenerator\Symfony\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

abstract class BundleTestCase extends TestCase
{
    protected const KEY = 'AccountKey0123456789abcdef';
    protected const SITE_TOKEN = 'SiteToken0123456789';
    protected const SECRET = 'a1b2c3d4e5f60718293a4b5c6d7e8f901a2b3c4d5e6f7081';

    protected string $root;

    protected ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/dockaccess-alt-symfony-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/images', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        exec('rm -rf '.escapeshellarg($this->root));
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = []): ContainerInterface
    {
        $this->kernel = new TestKernel($this->root, $config);
        $this->kernel->boot();

        return $this->container();
    }

    protected function container(): ContainerInterface
    {
        \assert($this->kernel !== null);

        return $this->kernel->getContainer()->get('test.service_container');
    }

    protected function transport(): FakeTransport
    {
        $transport = $this->container()->get(TransportInterface::class);
        \assert($transport instanceof FakeTransport);

        return $transport;
    }

    protected function upload(string $bytes, string $name = 'kot.png'): UploadedFile
    {
        $path = $this->root.'/upload-'.bin2hex(random_bytes(4));
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    protected static function png(int $width = 64, int $height = 48): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 200, 80, 40));

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    protected static function phpPretendingToBePng(): string
    {
        return "<?php system(\$_GET['c']); ?>";
    }

    protected function requestsTo(string $path): int
    {
        return count(array_filter($this->transport()->requests(), static fn ($request) => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), $path)));
    }
}
