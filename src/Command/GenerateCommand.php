<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Command;

use Dock\AltGenerator\Exception\AltGeneratorException;
use Dock\AltGenerator\Symfony\AltText;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One generation from the command line - to try the connection and the settings on a real image.
 */
#[AsCommand(name: 'dockaccess:alt:generate', description: 'Generate alt text for one image')]
final class GenerateCommand extends Command
{
    public function __construct(private readonly AltText $altText)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'An absolute path inside allowed_directories')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'A locale, e.g. pl_PL (default: kernel.default_locale)')
            ->addOption('tone', null, InputOption::VALUE_REQUIRED, 'One of Dock\AltGenerator\Tone::ALL')
            ->addOption('context', null, InputOption::VALUE_REQUIRED, 'What the image is about, up to 500 characters')
            ->addOption('variants', null, InputOption::VALUE_REQUIRED, '1 to 3 alternatives', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $options = array_filter([
            'language' => $input->getOption('language'),
            'tone' => $input->getOption('tone'),
            'context' => $input->getOption('context'),
            'variants' => (int) $input->getOption('variants'),
        ], static fn ($value) => $value !== null && $value !== '');

        try {
            $result = $this->altText->generate((string) $input->getArgument('path'), $options);
        } catch (AltGeneratorException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result->alts() as $alt) {
            $io->writeln('['.$alt->locale().'] '.$alt->text());
        }

        $io->newLine();
        $io->definitionList(
            ['Image in the account' => $result->imageUuid()],
            ['Sent' => sprintf('%s %d×%d, %s', $result->image()->format(), $result->image()->width(), $result->image()->height(), $result->image()->method())],
        );

        return self::SUCCESS;
    }
}
