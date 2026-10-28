<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Command\Concerns;

use Dock\AltGenerator\Exception\ConnectionException;

/**
 * What to do about a refusal of dockaccess.org, in words a developer can act on.
 *
 * @internal
 */
trait ExplainsConnectionErrors
{
    protected function explain(ConnectionException $e): string
    {
        return match ($e->getReason()) {
            ConnectionException::INVALID_CREDENTIALS => 'The login or the password is wrong (or, for a stored connection, the account no longer knows it).',
            ConnectionException::EMAIL_NOT_VERIFIED => 'Confirm the e-mail address of the dockaccess.org account first.',
            ConnectionException::TOKEN_NOT_OWNED => 'This site token belongs to another account. Copy it from the alt generator tab of the site in your account.',
            ConnectionException::DOMAIN_NOT_ALLOWED => 'The address of this application is not one of the domains of the site. Add the domain to the site in your account, or pass --url.',
            ConnectionException::ALT_DISABLED => 'Switch the alt generator on for this site in your dockaccess.org account, then connect again.',
            ConnectionException::ALT_PAUSED => 'The alt generator of this site is paused by its plan. Upgrade the plan, then connect again.',
            ConnectionException::ALREADY_CONNECTED => 'Another installation is connected with this address. Run again with --overwrite to replace it.',
            ConnectionException::DISCONNECTED => 'The connection was removed in the account. Run "bin/console dockaccess:alt:connect" again.',
            ConnectionException::NOT_CONNECTED => 'The account has no connection for this address.',
            ConnectionException::TOO_MANY_ATTEMPTS => 'Too many failed attempts from this server. Wait 15 minutes and try again.',
            default => $e->getApiMessage(),
        };
    }
}
