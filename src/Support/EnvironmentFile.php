<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Support;

use RuntimeException;

/**
 * Sets and removes variables in a dotenv file (`.env.local`), leaving every other line as it was.
 *
 * @internal
 */
final class EnvironmentFile
{
    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    /**
     * `.env.local` usually does not exist yet; it is created (readable by its owner only) when its
     * directory can be written.
     */
    public function isWritable(): bool
    {
        return is_file($this->path) ? is_writable($this->path) : is_dir(dirname($this->path)) && is_writable(dirname($this->path));
    }

    /**
     * @param array<string, string> $values
     */
    public function set(array $values): void
    {
        $content = $this->read();

        foreach ($values as $name => $value) {
            $line = $name.'='.self::quote($value);
            $pattern = '/^'.preg_quote($name, '/').'=.*$/m';

            if (preg_match($pattern, $content) === 1) {
                $content = (string) preg_replace_callback($pattern, static fn () => $line, $content, 1);
            } else {
                $content = rtrim($content, "\n").($content === '' ? '' : "\n").$line."\n";
            }
        }

        $this->write($content);
    }

    /**
     * @param list<string> $names
     */
    public function remove(array $names): void
    {
        $content = $this->read();

        foreach ($names as $name) {
            $content = (string) preg_replace('/^'.preg_quote($name, '/').'=.*(\R|$)/m', '', $content);
        }

        $this->write($content);
    }

    private function read(): string
    {
        if (! $this->isWritable()) {
            throw new RuntimeException(sprintf('%s cannot be written.', $this->path));
        }

        if (! is_file($this->path)) {
            return '';
        }

        $content = file_get_contents($this->path);

        if ($content === false) {
            throw new RuntimeException(sprintf('%s cannot be read.', $this->path));
        }

        return $content;
    }

    private function write(string $content): void
    {
        $created = ! is_file($this->path);

        if (file_put_contents($this->path, $content, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('%s cannot be written.', $this->path));
        }

        if ($created) {
            @chmod($this->path, 0600);
        }
    }

    /**
     * The keys are URL-unreserved characters and go unquoted; anything else is double-quoted.
     */
    private static function quote(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9._~\/:-]*$/D', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
}
