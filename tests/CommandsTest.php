<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Tests;

use Dock\AltGenerator\Http\Response;
use Dock\AltGenerator\Symfony\DockAccessAltGeneratorBundle;
use Dock\AltGenerator\Symfony\Testing\FakeTransport;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandsTest extends BundleTestCase
{
    private const NEW_KEY = 'NewAccountKey0123456789';
    private const NEW_SECRET = 'ffeeddccbbaa99887766554433221100ffeeddccbbaa9988';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DOCKACCESS_PASSWORD=Hunter2!');
    }

    protected function tearDown(): void
    {
        putenv('DOCKACCESS_PASSWORD');
        parent::tearDown();
    }

    public function test_connect_writes_the_keys_to_env_local(): void
    {
        $this->boot();
        $this->transport()->respond('/integrations/connect', $this->connected());

        $tester = $this->command('dockaccess:alt:connect');
        $tester->execute(['--token' => self::SITE_TOKEN, '--login' => 'anna@example.com'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Anna Nowak', $tester->getDisplay());

        $env = (string) file_get_contents($this->root.'/.env.local');
        $this->assertSame('DOCKACCESS_ALT_KEY='.self::NEW_KEY."\nDOCKACCESS_ALT_SITE_TOKEN=".self::SITE_TOKEN."\nDOCKACCESS_ALT_SECRET=".self::NEW_SECRET."\n", $env);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->root.'/.env.local')), -4));

        $payload = json_decode((string) $this->transport()->requests()[0]->body(), true);
        $this->assertSame('symfony', $payload['system']);
        // framework.router.default_uri
        $this->assertSame('https://shop.example.com', $payload['home_url']);
        $this->assertSame(DockAccessAltGeneratorBundle::VERSION, $payload['module_version']);
    }

    public function test_connect_asks_before_replacing_another_installation(): void
    {
        $this->boot();
        $this->transport()->respond(
            '/integrations/connect',
            FakeTransport::json(['success' => false, 'reason' => 'already_connected', 'detail' => 'Already connected.'], 409),
            $this->connected(),
        );

        $tester = $this->command('dockaccess:alt:connect');
        $tester->setInputs(['yes']);
        $tester->execute(['--token' => self::SITE_TOKEN, '--login' => 'anna@example.com']);

        $tester->assertCommandIsSuccessful();
        $this->assertTrue(json_decode((string) $this->transport()->requests()[1]->body(), true)['overwrite']);
    }

    public function test_connect_explains_a_refusal(): void
    {
        $this->boot();
        $this->transport()->respond('/integrations/connect', FakeTransport::json(['success' => false, 'reason' => 'domain_not_allowed', 'detail' => 'Token is not assigned to this domain.'], 403));

        $tester = $this->command('dockaccess:alt:connect');
        $tester->execute(['--token' => self::SITE_TOKEN, '--login' => 'anna@example.com', '--url' => 'https://other.example.com'], ['interactive' => false]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not one of the domains', preg_replace('/\s+/', ' ', $tester->getDisplay()));
        $this->assertFileDoesNotExist($this->root.'/.env.local');
    }

    public function test_status_shows_the_connection_and_the_monthly_usage(): void
    {
        $this->boot();
        $this->transport()->withUsage(15, 2000, 'month');

        $tester = $this->command('dockaccess:alt:status');
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('active', $tester->getDisplay());
        $this->assertStringContainsString('15 / 2000 (1985 left)', $tester->getDisplay());
    }

    public function test_disconnect_removes_the_keys(): void
    {
        $this->boot();
        file_put_contents($this->root.'/.env.local', "APP_ENV=prod\nDOCKACCESS_ALT_KEY=".self::KEY."\nDOCKACCESS_ALT_SITE_TOKEN=".self::SITE_TOKEN."\nDOCKACCESS_ALT_SECRET=".self::SECRET."\n");

        $tester = $this->command('dockaccess:alt:disconnect');
        $tester->execute(['--login' => 'anna@example.com', '--url' => 'https://shop.example.com'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame("APP_ENV=prod\n", file_get_contents($this->root.'/.env.local'));
    }

    public function test_generate_prints_the_alts(): void
    {
        $this->boot();
        file_put_contents($this->root.'/images/kot.png', self::png());

        $tester = $this->command('dockaccess:alt:generate');
        $tester->execute(['path' => $this->root.'/images/kot.png', '--language' => 'pl']);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('[pl] An image', $tester->getDisplay());
    }

    private function command(string $name): CommandTester
    {
        \assert($this->kernel !== null);

        return new CommandTester((new Application($this->kernel))->find($name));
    }

    private function connected(): Response
    {
        return FakeTransport::json([
            'success' => true,
            'external_token' => self::NEW_KEY,
            'user' => ['display_name' => 'Anna Nowak'],
            'token' => ['token' => self::SITE_TOKEN, 'alt_enabled' => true],
            'integration' => ['id' => 42, 'system' => 'symfony', 'home_url' => 'https://shop.example.com', 'secret' => self::NEW_SECRET, 'domain_verified' => true],
        ]);
    }
}
