<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Command;

use Dock\AltGenerator\Connection\ConnectionClient;
use Dock\AltGenerator\Exception\AltGeneratorException;
use Dock\AltGenerator\Exception\ConnectionException;
use Dock\AltGenerator\Symfony\Command\Concerns\ExplainsConnectionErrors;
use Dock\AltGenerator\Symfony\ConnectionGuard;
use Dock\AltGenerator\Symfony\Support\ConfigFactory;
use Dock\AltGenerator\Symfony\Support\EnvironmentFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Disconnects this application from the account and removes its keys from `.env.local`. The
 * account can do the same from its side (alt generator tab of the site), without the password.
 */
#[AsCommand(name: 'dockaccess:alt:disconnect', description: 'Disconnect this application from the alt generator of your dockaccess.org account')]
final class DisconnectCommand extends Command
{
    use ExplainsConnectionErrors;

    private const VARIABLES = ['DOCKACCESS_ALT_KEY', 'DOCKACCESS_ALT_SITE_TOKEN', 'DOCKACCESS_ALT_SECRET'];

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        private readonly ConnectionClient $client,
        private readonly ConnectionGuard $guard,
        private readonly array $settings,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('login', null, InputOption::VALUE_REQUIRED, 'The e-mail address of the dockaccess.org account')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'The address this application was connected with')
            ->addOption('keep-env', null, InputOption::VALUE_NONE, 'Keep the keys in .env.local');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $token = ConfigFactory::string($this->settings['site_token'] ?? null);

        if ($token === null) {
            $io->error('This application is not connected.');

            return self::FAILURE;
        }

        $login = trim((string) ($input->getOption('login') ?: $io->ask('E-mail address of the dockaccess.org account')));
        $password = (string) (getenv('DOCKACCESS_PASSWORD') ?: ($input->isInteractive() ? $io->askHidden('Password') : ''));
        $url = (string) ($input->getOption('url') ?: $io->ask('Address this application was connected with'));

        try {
            $this->client->disconnect($token, $login, $password, $url);
        } catch (ConnectionException $e) {
            // Gone on their side already: forget it here as well.
            if ($e->getReason() !== ConnectionException::NOT_CONNECTED) {
                $io->error($this->explain($e));

                return self::FAILURE;
            }
        } catch (AltGeneratorException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $this->guard->forget();
        $io->success('Disconnected.');

        $env = new EnvironmentFile($this->projectDir.'/.env.local');

        if (! $input->getOption('keep-env') && is_file($env->path()) && $env->isWritable()) {
            $env->remove(self::VARIABLES);
            $io->writeln('The keys were removed from '.$env->path().'.');
        } elseif (! $input->getOption('keep-env')) {
            $io->warning('Remove '.implode(', ', self::VARIABLES).' from the environment of the application.');
        }

        return self::SUCCESS;
    }
}
