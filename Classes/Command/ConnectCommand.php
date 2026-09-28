<?php

declare(strict_types=1);

namespace N9c\Monitor\Command;

use N9c\Monitor\Service\ConfigStore;
use N9c\Monitor\Service\ConnectService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * n9c:monitor:connect <code> [--force] - siehe ConnectService.
 */
final class ConnectCommand extends Command
{
    public function __construct(
        private readonly ConnectService $connectService,
        private readonly ConfigStore $configStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Einmal-Verbindungscode (n9c-enroll-...)')
            ->addOption('endpoint', null, InputOption::VALUE_REQUIRED, 'API-Adresse', '')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Bestehende Verbindung erneuern (Instanz und Verlauf bleiben erhalten)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->connectService->connect(
            (string)$input->getArgument('code'),
            (string)$input->getOption('endpoint'),
            (bool)$input->getOption('force')
        );
        if (!$result['ok']) {
            $io->error($result['message']);
            return Command::FAILURE;
        }
        $io->success([
            $result['message'],
            'Zugangsdaten gespeichert in ' . $this->configStore->getFilePath(),
            'Naechster Schritt: n9c:monitor:report --dry-run (Vorschau) bzw. n9c:monitor:report (senden)',
        ]);
        return Command::SUCCESS;
    }
}
