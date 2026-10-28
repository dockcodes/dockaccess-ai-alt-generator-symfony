<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Exception;

use Dock\AltGenerator\Exception\AltGeneratorException;

/**
 * dockaccess.org says this application may not generate now. Nothing was sent to the generator.
 */
final class GeneratorUnavailableException extends \RuntimeException implements AltGeneratorException
{
    /** The site's plan paused the generator; an upgrade in the account switches it back on. */
    public const PAUSED = 'alt_paused';

    /** The owner switched the generator off for the site in the account. */
    public const DISABLED = 'alt_disabled';

    /** The owner disconnected this application in the account; run `dockaccess:alt:connect` again. */
    public const DISCONNECTED = 'disconnected';

    private const MESSAGES = [
        self::PAUSED => 'The alt generator for this site is paused by the plan. Upgrade the plan in your dockaccess.org account.',
        self::DISABLED => 'The alt generator is switched off for this site in your dockaccess.org account.',
        self::DISCONNECTED => 'This application was disconnected from your dockaccess.org account. Run "bin/console dockaccess:alt:connect".',
    ];

    public function __construct(private readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? self::MESSAGES[self::DISABLED]);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
