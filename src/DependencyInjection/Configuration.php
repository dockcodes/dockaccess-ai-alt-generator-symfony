<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\DependencyInjection;

use Dock\AltGenerator\Config;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * `dockaccess_alt_generator:` - everything has a default; a connected application needs no
 * configuration file at all. The three secrets come from `bin/console dockaccess:alt:connect`.
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('dockaccess_alt_generator');

        $tree->getRootNode()
            ->children()
                ->scalarNode('account_key')
                    ->info('The key the generator is called with (written by dockaccess:alt:connect)')
                    ->defaultValue('%env(default::DOCKACCESS_ALT_KEY)%')
                ->end()
                ->scalarNode('site_token')
                    ->info('The site in the account; also the image set of this application')
                    ->defaultValue('%env(default::DOCKACCESS_ALT_SITE_TOKEN)%')
                ->end()
                ->scalarNode('connection_secret')
                    ->info('Authenticates the connection check')
                    ->defaultValue('%env(default::DOCKACCESS_ALT_SECRET)%')
                ->end()
                ->arrayNode('verify')
                    ->info('Ask dockaccess.org whether the generator is still on for the site before generating')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('ttl')->info('Seconds the answer is remembered')->defaultValue(300)->min(1)->end()
                        ->scalarNode('cache_pool')->defaultValue('cache.app')->end()
                    ->end()
                ->end()
                ->arrayNode('defaults')
                    ->info('What every generation asks for unless the call says otherwise')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('language')->info('A locale; null follows the request locale, then kernel.default_locale')->defaultNull()->end()
                        ->scalarNode('fallback_language')->info('Used when the locale is not a supported language')->defaultValue('en')->end()
                        ->scalarNode('tone')->info('One of Dock\AltGenerator\Tone::ALL')->defaultValue('neutral')->end()
                        ->integerNode('variants')->defaultValue(1)->min(1)->max(3)->end()
                        ->scalarNode('set_ref')->info('The image set in the account; null uses the site token')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('allowed_directories')
                    ->info('Absolute paths are read only inside these directories (symlinks resolved). Never the project root.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['%kernel.project_dir%/public/uploads'])
                ->end()
                ->arrayNode('storages')
                    ->info('Flysystem storage service ids GenerateAltText messages may name (storages tagged "flysystem.storage" are added)')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('image')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('mode')
                            ->info('auto: re-encode with GD, strip metadata where GD cannot; reencode: re-encode or refuse; strip: never decode')
                            ->values(['auto', 'reencode', 'strip'])
                            ->defaultValue('auto')
                        ->end()
                        ->integerNode('max_bytes')->defaultValue(10 * 1024 * 1024)->min(1024)->max(10 * 1024 * 1024)->end()
                        ->integerNode('max_edge')->info('Longest side of the image that is sent, in pixels')->defaultValue(2048)->min(256)->max(8192)->end()
                    ->end()
                ->end()
                ->integerNode('max_alt_length')->defaultValue(2000)->min(16)->max(2000)->end()
                ->arrayNode('http')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('connect_timeout')->info('ms')->defaultValue(5000)->min(100)->max(60000)->end()
                        ->integerNode('timeout')->info('ms - a generation includes an AI model call')->defaultValue(90000)->min(100)->max(300000)->end()
                        ->integerNode('connect_retries')->info('Only failures before anything was sent')->defaultValue(1)->min(0)->max(3)->end()
                        ->scalarNode('proxy')->info('"host:port" or "http://user:pass@host:port"')->defaultValue('%env(default::DOCKACCESS_ALT_PROXY)%')->end()
                        ->scalarNode('ca_bundle')->info('For hosts whose system CA store is outdated')->defaultNull()->end()
                    ->end()
                ->end()
                ->scalarNode('base_url')->info('Only for tests against a staging environment')->defaultValue(Config::DEFAULT_BASE_URL)->end()
                ->scalarNode('connection_url')->info('Only for tests against a staging environment')->defaultValue(Config::DEFAULT_CONNECTION_URL)->end()
            ->end();

        return $tree;
    }
}
