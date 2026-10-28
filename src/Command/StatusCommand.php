<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Command;

use Dock\AltGenerator\Connection\Verification;
use Dock\AltGenerator\Exception\AltGeneratorException;
use Dock\AltGenerator\Exception\ConnectionException;
use Dock\AltGenerator\Result\Usage;
use Dock\AltGenerator\Symfony\AltText;
use Dock\AltGenerator\Symfony\Command\Concerns\ExplainsConnectionErrors;
use Dock\AltGenerator\Symfony\Support\ConfigFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Whether the connection is on, and how much of the plan is left.
 */
#[AsCommand(name: 'dockaccess:alt:status', description: 'Show the connection to dockaccess.org and the alt generator usage')]
final class StatusCommand extends Command
{
    use ExplainsConnectionErrors;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(private readonly AltText $altText, private readonly array $settings)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $guard = $this->altText->guard();

        if (! $guard->isConfigured() || ConfigFactory::string($this->settings['account_key'] ?? null) === null) {
            $io->error('This application is not connected. Run "bin/console dockaccess:alt:connect".');

            return self::FAILURE;
        }

        try {
            $verification = $guard->verify(true);
        } catch (ConnectionException $e) {
            $io->error($this->explain($e));

            return self::FAILURE;
        } catch (AltGeneratorException $e) {
            $io->error('dockaccess.org could not be asked: '.$e->getMessage());

            return self::FAILURE;
        }

        $rows = [['Connection' => match ($verification->state()) {
            Verification::ACTIVE => 'active',
            Verification::PAUSED => 'paused by the plan',
            default => 'generator switched off for the site',
        }]];

        if ($verification->websitesLimit() !== null) {
            $rows[] = ['Sites with the generator' => $verification->websitesUsed().' / '.$verification->websitesLimit()];
        }

        try {
            $usage = $this->altText->usage();
        } catch (AltGeneratorException $e) {
            $usage = $verification->usage();

            if ($usage === null) {
                $io->warning('The usage could not be read: '.$e->getMessage());
            }
        }

        if ($usage !== null) {
            $rows[] = [($usage->isMonthly() ? 'Alts this month' : 'Alts today') => $usage->used().' / '.$usage->limit().' ('.$usage->remaining().' left)'];
            $rows[] = ['Limit renews' => self::renewal($usage)];
        }

        $io->definitionList(...$rows);

        return $verification->isActive() ? self::SUCCESS : self::FAILURE;
    }

    private static function renewal(Usage $usage): string
    {
        $resetsAt = $usage->resetsAt();

        if ($resetsAt !== null) {
            return $resetsAt->format('Y-m-d H:i').' UTC';
        }

        return $usage->isMonthly() ? 'on the 1st of next month (UTC)' : 'at midnight (UTC)';
    }
}
