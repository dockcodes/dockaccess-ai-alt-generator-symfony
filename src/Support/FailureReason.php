<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Support;

use Dock\AltGenerator\Exception\AuthenticationException;
use Dock\AltGenerator\Exception\ConfigurationException;
use Dock\AltGenerator\Exception\DailyLimitReachedException;
use Dock\AltGenerator\Exception\ImageRejectedException;
use Dock\AltGenerator\Exception\InvalidImageException;
use Dock\AltGenerator\Exception\RateLimitedException;
use Dock\AltGenerator\Exception\ServerException;
use Dock\AltGenerator\Exception\TransportException;
use Dock\AltGenerator\Symfony\Exception\GeneratorUnavailableException;
use Throwable;

/**
 * A short, stable name for why a generation failed - for events, logs and translations.
 */
final class FailureReason
{
    public const LIMIT_REACHED = 'limit_reached';
    public const RATE_LIMITED = 'rate_limited';
    public const INVALID_KEY = 'invalid_key';
    public const IMAGE_REJECTED = 'image_rejected';
    public const UNAVAILABLE = 'unavailable';
    public const NOT_CONFIGURED = 'not_configured';
    public const ERROR = 'error';

    public static function of(Throwable $e): string
    {
        return match (true) {
            $e instanceof InvalidImageException => $e->getReason(),
            $e instanceof GeneratorUnavailableException => $e->getReason(),
            $e instanceof DailyLimitReachedException => self::LIMIT_REACHED,
            $e instanceof RateLimitedException => self::RATE_LIMITED,
            $e instanceof AuthenticationException => self::INVALID_KEY,
            $e instanceof ImageRejectedException => self::IMAGE_REJECTED,
            $e instanceof ServerException, $e instanceof TransportException => self::UNAVAILABLE,
            $e instanceof ConfigurationException => self::NOT_CONFIGURED,
            default => self::ERROR,
        };
    }

    /**
     * Whether trying the same image again later can succeed.
     */
    public static function isTemporary(Throwable $e): bool
    {
        return $e instanceof RateLimitedException
            || $e instanceof ServerException
            || ($e instanceof TransportException && $e->isRetryable());
    }
}
