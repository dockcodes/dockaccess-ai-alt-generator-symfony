<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony;

use Dock\AltGenerator\Connection\ConnectionClient;
use Dock\AltGenerator\Connection\Verification;
use Dock\AltGenerator\Exception\ConnectionException;
use Dock\AltGenerator\Exception\ServerException;
use Dock\AltGenerator\Exception\TransportException;
use Dock\AltGenerator\Exception\UnexpectedResponseException;
use Dock\AltGenerator\Secret;
use Dock\AltGenerator\Symfony\Exception\GeneratorUnavailableException;
use Dock\AltGenerator\Symfony\Support\ConfigFactory;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Asks dockaccess.org whether this application may generate, the way the CMS plugins do before
 * every generation - here with the answer cached for `verify.ttl` seconds.
 *
 * It stops generation when the owner disconnected the application in the account, switched the
 * generator off for the site or the plan paused it. When dockaccess.org cannot be reached it lets
 * generation through: the generator still enforces the account's limits, and an outage of the
 * account should not stop a site that is otherwise fine.
 */
final class ConnectionGuard
{
    public const CACHE_KEY = 'dockaccess_alt_generator.verification';

    /** How long an unreachable dockaccess.org is not asked again, in seconds. */
    private const OUTAGE_TTL = 60;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        private readonly ConnectionClient $client,
        private readonly CacheItemPoolInterface $cache,
        private readonly array $settings,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Whether the site token and the connection secret are set - without them there is nothing to verify.
     */
    public function isConfigured(): bool
    {
        return $this->siteToken() !== null && $this->secret() !== null;
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->settings['verify']['enabled'] ?? true) && $this->isConfigured();
    }

    /**
     * @throws GeneratorUnavailableException
     */
    public function ensureActive(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $item = $this->cache->getItem(self::CACHE_KEY);
        $state = $item->isHit() ? $item->get() : null;

        if (! is_string($state)) {
            $state = $this->check();
        }

        if ($state !== Verification::ACTIVE) {
            throw new GeneratorUnavailableException($state);
        }
    }

    /**
     * Asks now, without the cache, and remembers the answer. For the status command.
     *
     * @throws ConnectionException when the connection is gone (the cache then says so too)
     * @throws TransportException
     */
    public function verify(bool $withUsage = false): Verification
    {
        try {
            $verification = $this->client->verify((string) $this->siteToken(), new Secret((string) $this->secret()), $withUsage);
        } catch (ConnectionException $e) {
            if ($e->isConnectionGone()) {
                $this->remember(GeneratorUnavailableException::DISCONNECTED, $this->ttl());
            }

            throw $e;
        }

        $this->remember($verification->state(), $this->ttl());

        return $verification;
    }

    public function forget(): void
    {
        $this->cache->deleteItem(self::CACHE_KEY);
    }

    private function check(): string
    {
        try {
            return $this->verify()->state();
        } catch (ConnectionException $e) {
            if ($e->isConnectionGone()) {
                return GeneratorUnavailableException::DISCONNECTED;
            }

            $this->logger?->warning('dockaccess.org refused to verify the alt generator connection: '.$e->getMessage());
        } catch (TransportException|ServerException|UnexpectedResponseException $e) {
            $this->logger?->warning('dockaccess.org could not verify the alt generator connection: '.$e->getMessage());
        }

        $this->remember(Verification::ACTIVE, self::OUTAGE_TTL);

        return Verification::ACTIVE;
    }

    private function remember(string $state, int $ttl): void
    {
        $this->cache->save($this->cache->getItem(self::CACHE_KEY)->set($state)->expiresAfter($ttl));
    }

    private function ttl(): int
    {
        return max(1, (int) ($this->settings['verify']['ttl'] ?? 300));
    }

    private function siteToken(): ?string
    {
        return ConfigFactory::string($this->settings['site_token'] ?? null);
    }

    private function secret(): ?string
    {
        return ConfigFactory::string($this->settings['connection_secret'] ?? null);
    }
}
