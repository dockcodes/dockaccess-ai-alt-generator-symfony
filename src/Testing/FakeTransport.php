<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Testing;

use Dock\AltGenerator\Exception\TransportException;
use Dock\AltGenerator\Http\Request;
use Dock\AltGenerator\Http\Response;
use Dock\AltGenerator\Http\TransportInterface;
use PHPUnit\Framework\Assert;

/**
 * Stands in for dockaccess.org in tests: answers generations with canned alts,
 * usage with the numbers given to `withUsage()`, and verification as active. Remembers every request.
 *
 * Register it in the test environment and the bundle uses it instead of cURL:
 *
 *     # config/services_test.yaml
 *     services:
 *         Dock\AltGenerator\Http\TransportInterface:
 *             class: Dock\AltGenerator\Symfony\Testing\FakeTransport
 *             factory: [Dock\AltGenerator\Symfony\Testing\FakeTransport, alts]
 *             arguments: ['A red cat on a windowsill']
 *             public: true
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<Request> */
    private array $requests = [];

    private int $generated = 0;

    /** @var array{used: int, limit: int, period: string} */
    private array $usage = ['used' => 0, 'limit' => 100, 'period' => 'month'];

    /**
     * @param list<string> $alts
     * @param array<string, list<Response|TransportException>> $overrides answers by path, given in turn, the last one repeated
     */
    private function __construct(private readonly array $alts, private array $overrides = []) {}

    public static function alts(string ...$alts): self
    {
        return new self(array_values($alts));
    }

    /**
     * Answers requests to `$path` ("/api/generate", "/api/user", "/integrations/verify") with this
     * response - to test how an application handles a spent limit or a refusal. A TransportException
     * stands for dockaccess.org being out of reach. Several answers are given in turn, the last one
     * repeated.
     */
    public function respond(string $path, Response|TransportException ...$responses): self
    {
        $this->overrides[$path] = array_values($responses);

        return $this;
    }

    public function withUsage(int $used, int $limit, string $period = 'month'): self
    {
        $this->usage = ['used' => $used, 'limit' => $limit, 'period' => $period];

        return $this;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        foreach ($this->overrides as $suffix => $responses) {
            if (! str_ends_with($path, $suffix) || $responses === []) {
                continue;
            }

            $response = $responses[0];

            if (count($responses) > 1) {
                array_shift($this->overrides[$suffix]);
            }

            if ($response instanceof TransportException) {
                throw $response;
            }

            return $response;
        }

        if (str_ends_with($path, '/api/user')) {
            return self::json(['requests' => $this->usage]);
        }

        if (str_ends_with($path, '/integrations/verify')) {
            return self::json(['success' => true, 'alt_enabled' => true, 'token' => ['alt_enabled' => true, 'alt_paused' => false]]);
        }

        if (str_ends_with($path, '/api/generate')) {
            $alt = $this->alts[min($this->generated, count($this->alts) - 1)] ?? 'An image';
            $this->generated++;

            return self::json(['data' => [
                'set_uuid' => '00000000-0000-4000-8000-000000000001',
                'set_ref' => null,
                'image' => [
                    'uuid' => sprintf('00000000-0000-4000-8000-%012d', $this->generated + 1),
                    'alts' => [['alt' => $alt, 'locale' => self::language($request)]],
                ],
            ]]);
        }

        return self::json(['success' => true]);
    }

    /**
     * @return list<Request>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * How many images were sent to the generator.
     */
    public function generatedCount(): int
    {
        return count(array_filter($this->requests, static fn (Request $request) => str_contains($request->url(), '/api/generate')));
    }

    public function assertGenerated(int $times): void
    {
        Assert::assertSame($times, $this->generatedCount(), sprintf('Expected %d generations, got %d.', $times, $this->generatedCount()));
    }

    public function assertNothingGenerated(): void
    {
        $this->assertGenerated(0);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, $headers, (string) json_encode($body));
    }

    private static function language(Request $request): string
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return is_string($query['lang'] ?? null) ? $query['lang'] : 'en';
    }
}
