<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Support;

use Dock\AltGenerator\Config;
use Dock\AltGenerator\Image\ImagePolicy;

/**
 * Turns the `dockaccess_alt_generator` configuration into the core's Config. Every value goes through the core's own
 * validation, so a wrong setting fails with a ConfigurationException naming it.
 *
 * @internal
 */
final class ConfigFactory
{
    /**
     * @param array<string, mixed> $settings
     * @param bool $withKey false builds the settings without the account key - for connecting
     */
    public static function make(array $settings, string $userAgent, bool $withKey = true): Config
    {
        $key = self::string($settings['account_key'] ?? null);
        $config = $withKey && $key !== null ? Config::create($key) : Config::anonymous();

        $http = (array) ($settings['http'] ?? []);
        $image = (array) ($settings['image'] ?? []);

        $config = $config
            ->withBaseUrl(self::string($settings['base_url'] ?? null) ?? Config::DEFAULT_BASE_URL)
            ->withConnectionUrl(self::string($settings['connection_url'] ?? null) ?? Config::DEFAULT_CONNECTION_URL)
            ->withTimeouts((int) ($http['connect_timeout'] ?? 5000), (int) ($http['timeout'] ?? 90000))
            ->withConnectRetries((int) ($http['connect_retries'] ?? 1))
            ->withMaxAltLength((int) ($settings['max_alt_length'] ?? 2000))
            ->withUserAgentSuffix($userAgent);

        if (($proxy = self::string($http['proxy'] ?? null)) !== null) {
            $config = $config->withProxy($proxy);
        }

        if (($bundle = self::string($http['ca_bundle'] ?? null)) !== null) {
            $config = $config->withCaBundle($bundle);
        }

        $policy = ImagePolicy::secure()
            ->withMode(self::string($image['mode'] ?? null) ?? ImagePolicy::MODE_AUTO)
            ->withMaxEdge((int) ($image['max_edge'] ?? 2048));

        if (isset($image['max_bytes'])) {
            $policy = $policy->withMaxBytes((int) $image['max_bytes']);
        }

        return $config->withImagePolicy($policy);
    }

    public static function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
