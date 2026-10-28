<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Command;

use Dock\AltGenerator\Connection\Connection;
use Dock\AltGenerator\Connection\ConnectionClient;
use Dock\AltGenerator\Connection\ConnectRequest;
use Dock\AltGenerator\Exception\AltGeneratorException;
use Dock\AltGenerator\Exception\ConnectionException;
use Dock\AltGenerator\Symfony\Command\Concerns\ExplainsConnectionErrors;
use Dock\AltGenerator\Symfony\ConnectionGuard;
use Dock\AltGenerator\Symfony\DockAccessAltGeneratorBundle;
use Dock\AltGenerator\Symfony\Support\EnvironmentFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\RequestContext;

/**
 * Connects this application to a site in a dockaccess.org account, as the CMS plugins do, and
 * writes the account key, the site token and the connection secret to `.env.local`.
 *
 * The password is asked for without echo and used once; it is not stored anywhere.
 */
#[AsCommand(name: 'dockaccess:alt:connect', description: 'Connect this application to the alt generator of your dockaccess.org account')]
final class ConnectCommand extends Command
{
    use ExplainsConnectionErrors;

    public const SYSTEM = 'symfony';

    public function __construct(
        private readonly ConnectionClient $client,
        private readonly ConnectionGuard $guard,
        private readonly string $projectDir,
        private readonly ?RequestContext $requestContext = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'The site token, from the alt generator tab of the site in your dockaccess.org account')
            ->addOption('login', null, InputOption::VALUE_REQUIRED, 'The e-mail address of the dockaccess.org account')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'The address of this application, one of the domains of the site (default: framework.router.default_uri)')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Replace a connection another installation made with the same address')
            ->addOption('show', null, InputOption::VALUE_NONE, 'Print the three values instead of writing them to .env.local');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $token = trim((string) ($input->getOption('token') ?: $io->ask('Site token (alt generator tab of the site in your account)')));
        $login = trim((string) ($input->getOption('login') ?: $io->ask('E-mail address of the dockaccess.org account')));
        $password = (string) (getenv('DOCKACCESS_PASSWORD') ?: ($input->isInteractive() ? $io->askHidden('Password') : ''));
        $url = (string) ($input->getOption('url') ?: $this->defaultUrl() ?: $io->ask('Address of this application (one of the domains of the site)'));

        try {
            $request = ConnectRequest::create($token, $login, $password, self::SYSTEM, $url)
                ->withVersions(Kernel::VERSION, DockAccessAltGeneratorBundle::VERSION)
                ->withOverwrite((bool) $input->getOption('overwrite'));
        } catch (AltGeneratorException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $connection = $this->connect($io, $input, $request, $url);

        if ($connection === null) {
            return self::FAILURE;
        }

        $values = [
            'DOCKACCESS_ALT_KEY' => $connection->accountKey()->reveal(),
            'DOCKACCESS_ALT_SITE_TOKEN' => $connection->siteToken(),
            'DOCKACCESS_ALT_SECRET' => $connection->secret()->reveal(),
        ];

        $env = new EnvironmentFile($this->projectDir.'/.env.local');

        if ($input->getOption('show') || ! $env->isWritable()) {
            if (! $input->getOption('show')) {
                $io->warning($env->path().' cannot be written. Put these values into the environment of the application (or "bin/console secrets:set"):');
            }

            foreach ($values as $name => $value) {
                $io->writeln($name.'='.$value);
            }
        } else {
            $env->set($values);
            $io->success('The keys were written to '.$env->path().'.');
        }

        $this->guard->forget();

        $io->definitionList(
            ['Connected as' => $connection->accountName() ?: $login],
            ['Address' => $connection->homeUrl()],
            ['Shown in the account as' => $connection->system()],
        );

        if (! $connection->isDomainVerified()) {
            $io->warning('This is a local address. Connect again from the production domain before going live.');
        }

        if (! $connection->isAltEnabled()) {
            $io->warning('The alt generator is switched off for this site. Switch it on in your dockaccess.org account.');
        }

        return self::SUCCESS;
    }

    private function connect(SymfonyStyle $io, InputInterface $input, ConnectRequest $request, string $url): ?Connection
    {
        try {
            return $this->client->connect($request);
        } catch (ConnectionException $e) {
            if ($e->getReason() === ConnectionException::ALREADY_CONNECTED && ! $input->getOption('overwrite') && $input->isInteractive()
                && $io->confirm('Another installation is connected with '.$url.'. Replace that connection?', false)) {
                return $this->connect($io, $input, $request->withOverwrite(), $url);
            }

            $io->error($this->explain($e));
        } catch (AltGeneratorException $e) {
            $io->error($e->getMessage());
        }

        return null;
    }

    /**
     * The address from `framework.router.default_uri`; none when it is the "localhost" Symfony assumes without it.
     */
    private function defaultUrl(): ?string
    {
        if ($this->requestContext === null || in_array($this->requestContext->getHost(), ['', 'localhost'], true)) {
            return null;
        }

        $scheme = $this->requestContext->getScheme();
        $port = $scheme === 'https' ? $this->requestContext->getHttpsPort() : $this->requestContext->getHttpPort();
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$this->requestContext->getHost().($port !== $default ? ':'.$port : '').rtrim($this->requestContext->getBaseUrl(), '/');
    }
}
