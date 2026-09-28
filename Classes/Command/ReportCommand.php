<?php

declare(strict_types=1);

namespace N9c\Monitor\Command;

use N9c\Monitor\Service\NotConnectedException;
use N9c\Monitor\Service\ReportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * n9c:monitor:report [--dry-run] - sammelt die Kennzahlen und sendet sie.
 * Fuer den Scheduler-Task "Execute console commands" freigegeben. Ohne
 * Cronjob uebernimmt der automatische Report bei Seitenaufrufen.
 */
final class ReportCommand extends Command
{
    public function __construct(private readonly ReportService $reportService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen, was gesendet wuerde - nichts senden');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('dry-run')) {
            $report = $this->reportService->buildReport();
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $output->writeln($json);
            $io->note('Vorschau - es wurde nichts gesendet.');
            return Command::SUCCESS;
        }

        try {
            $result = $this->reportService->send('cli');
        } catch (NotConnectedException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Senden fehlgeschlagen: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $data = $result['data'];
        switch ($result['status']) {
            case 200:
                $counts = $data['counts'] ?? [];
                $io->success(sprintf(
                    'Report angenommen. Score %s (%s) - kritisch: %d, mittel: %d, ok: %d',
                    $data['score'] ?? '?',
                    $data['score_status'] ?? '?',
                    $counts['critical'] ?? 0,
                    $counts['medium'] ?? 0,
                    $counts['ok'] ?? 0
                ));
                return Command::SUCCESS;
            case 409:
            case 429:
                $io->note('Report wurde uebersprungen: ' . ($data['detail'] ?? 'zu frueh'));
                return Command::SUCCESS;
            case 401:
                $io->error('Abgelehnt (401): ' . ($data['detail'] ?? '') . ' - Verbindung bzw. Serverzeit (NTP) pruefen.');
                return Command::FAILURE;
            default:
                $io->error('Fehler (HTTP ' . $result['status'] . '): ' . json_encode($data, JSON_UNESCAPED_UNICODE));
                return Command::FAILURE;
        }
    }
}
