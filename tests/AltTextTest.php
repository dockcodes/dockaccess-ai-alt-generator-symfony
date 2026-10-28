<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Tests;

use Dock\AltGenerator\Exception\ConfigurationException;
use Dock\AltGenerator\Exception\InvalidImageException;
use Dock\AltGenerator\Symfony\AltText;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AltTextTest extends BundleTestCase
{
    public function test_it_generates_alt_text_for_an_upload_in_the_request_locale(): void
    {
        $container = $this->boot();
        $request = new Request();
        $request->setLocale('pl_PL');
        $container->get(RequestStack::class)->push($request);
        $this->transport()->respond('/api/generate', \Dock\AltGenerator\Symfony\Testing\FakeTransport::json(['data' => [
            'set_uuid' => '00000000-0000-4000-8000-000000000001',
            'image' => ['uuid' => '00000000-0000-4000-8000-000000000002', 'alts' => [['alt' => 'Rudy kot', 'locale' => 'pl']]],
        ]]));

        $result = $container->get(AltText::class)->generate($this->upload(self::png()));

        $this->assertSame('Rudy kot', $result->alt()->text());
        parse_str((string) parse_url($this->transport()->requests()[1]->url(), PHP_URL_QUERY), $query);
        $this->assertSame('pl', $query['lang']);
        // Every image of the application lands in one set of the account, named by the site token.
        $this->assertSame(self::SITE_TOKEN, $query['set_ref']);
        // The first request was the connection check.
        $this->assertSame(1, $this->requestsTo('/integrations/verify'));
    }

    public function test_the_call_overrides_the_configuration_and_the_configuration_the_locale(): void
    {
        $altText = $this->boot(['defaults' => ['language' => 'de_AT', 'tone' => 'formal']])->get(AltText::class);

        $options = $altText->options(['tone' => 'friendly', 'context' => 'Oak table']);

        $this->assertSame('de', $options->language());
        $this->assertSame('friendly', $options->tone());
        $this->assertSame('Oak table', $options->context());
    }

    public function test_without_a_request_the_default_locale_is_used(): void
    {
        $this->assertSame('en', $this->boot()->get(AltText::class)->options()->language());
    }

    public function test_it_reads_images_from_a_flysystem_storage(): void
    {
        $container = $this->boot();
        $storage = $container->get('test.storage');
        \assert($storage instanceof FilesystemOperator);
        $storage->write('products/42.png', self::png());

        $result = $container->get(AltText::class)->generateFromFilesystem($storage, 'products/42.png');

        $this->assertSame('An image', $result->alt()->text());
        $this->transport()->assertGenerated(1);
    }

    public function test_absolute_paths_are_read_only_inside_the_allowed_directories(): void
    {
        $altText = $this->boot()->get(AltText::class);
        file_put_contents($this->root.'/images/a.png', self::png());
        file_put_contents($this->root.'/outside.png', self::png());

        $altText->generate($this->root.'/images/a.png');

        try {
            $altText->generate($this->root.'/images/../outside.png');
            $this->fail('A file outside the allowed directories was read.');
        } catch (InvalidImageException $e) {
            $this->assertSame(InvalidImageException::NOT_ALLOWED, $e->getReason());
        }

        $this->transport()->assertGenerated(1);
    }

    public function test_code_dressed_as_an_image_never_leaves_the_server(): void
    {
        $altText = $this->boot()->get(AltText::class);

        try {
            $altText->generate($this->upload(self::phpPretendingToBePng(), 'photo.png'));
            $this->fail('A PHP file was accepted as an image.');
        } catch (InvalidImageException) {
            $this->transport()->assertNothingGenerated();
        }
    }

    public function test_checking_an_image_needs_no_connection(): void
    {
        $altText = $this->boot(['account_key' => null])->get(AltText::class);

        $prepared = $altText->prepare($this->upload(self::png(3000, 1000)));

        $this->assertSame(2048, max($prepared->width(), $prepared->height()));
    }

    public function test_generating_without_a_connection_says_so(): void
    {
        $altText = $this->boot(['account_key' => null])->get(AltText::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Connect the site');

        $altText->generate($this->upload(self::png()));
    }

    public function test_it_reads_a_monthly_usage(): void
    {
        $altText = $this->boot()->get(AltText::class);
        $this->transport()->withUsage(1990, 2000, 'month');

        $usage = $altText->usage();

        $this->assertTrue($usage->isMonthly());
        $this->assertSame(10, $usage->remaining());
    }

    public function test_the_secrets_come_from_the_environment_by_default(): void
    {
        $_SERVER['DOCKACCESS_ALT_KEY'] = 'EnvAccountKey0123456789';

        try {
            $settings = $this->boot(['account_key' => '%env(default::DOCKACCESS_ALT_KEY)%'])->get('dockaccess_alt_generator')->guard();
            $usage = $this->container()->get(AltText::class)->usage();
            parse_str((string) parse_url($this->transport()->requests()[0]->url(), PHP_URL_QUERY), $query);

            $this->assertSame('EnvAccountKey0123456789', $query['key']);
            $this->assertSame(0, $usage->used());
            $this->assertTrue($settings->isConfigured());
        } finally {
            unset($_SERVER['DOCKACCESS_ALT_KEY']);
        }
    }
}
