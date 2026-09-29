<?php

declare(strict_types=1);

namespace N9c\Monitor\Command;

use N9c\Monitor\Service\AutoReport;
use N9c\Monitor\Service\Collector;
use N9c\Monitor\Service\ConfigStore;
use N9c\Monitor\Service\ReportService;
use N9c\Monitor\Service\ReportStamp;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * n9c:monitor:status - Verbindung, automatischer Report und letztes Ergebnis.
 */
final class StatusCommand extends Command
{
    public function __construct(
        private readonly ConfigStore $configStore,
        private readonly ReportService $reportService,
        private readonly ReportStamp $stamp,
        private readonly AutoReport $autoReport,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->configStore->load();
        $last = $this->reportService->lastResult();
        $lastSent = $this->stamp->lastSentAt();
        $interval = $this->autoReport->intervalSeconds();

        $dashboard = $this->configStore->dashboardUrl();
        $rows = [
            ['Agent', Collector::AGENT_NAME . ' ' . Collector::AGENT_VERSION],
            ['Endpunkt', $config['endpoint']],
            ['Instanz-ID', $config['instance_id'] ?? '(nicht verbunden)'],
            ['Zugangsdaten aus', match ($config['source']) {
                'env' => 'Umgebungsvariablen',
                'file' => $this->configStore->getFilePath(),
                default => '-',
            }],
            ['Automatischer Report', $this->autoReport->isEnabled()
                ? 'an, alle ' . intdiv($interval, 3600) . ' h bei Seitenaufrufen'
                : 'aus (nur CLI/Scheduler)'],
            ['Naechster faellig', $lastSent === null ? 'sofort' : date('d.m.Y H:i', $lastSent + $interval)],
        ];
        if ($last !== null) {
            $rows[] = ['Letzter Report', date('d.m.Y H:i', (int)$last['time']) . ' (HTTP ' . $last['status'] . ', ' . ($last['trigger'] ?? 'cli') . ')'];
            if ($last['score'] !== null) {
                $rows[] = ['Score', $last['score'] . ' (' . $last['score_status'] . ')'];
                $counts = $last['counts'] ?? [];
                $rows[] = ['Befunde', sprintf('kritisch %d, mittel %d, ok %d', $counts['critical'] ?? 0, $counts['medium'] ?? 0, $counts['ok'] ?? 0)];
            }
            if (!empty($last['detail'])) {
                $rows[] = ['Meldung', $last['detail']];
            }
            if (is_array($last['plan'] ?? null)) {
                $rows[] = ['Tarif', ($last['plan']['label'] ?? '-') . (!empty($last['plan']['until']) ? ' bis ' . date('d.m.Y', (int)strtotime((string)$last['plan']['until'])) : '')];
            }
        } else {
            $rows[] = ['Letzter Report', '(noch keiner)'];
        }
        $rows[] = ['Dashboard', empty($config['instance_id'])
            ? $dashboard . '/dashboard/register (Konto erstellen, dann Verbindungscode erzeugen)'
            : $dashboard . '/dashboard/instances/' . $config['instance_id']];
        $io->table(['', ''], $rows);
        return Command::SUCCESS;
    }
}
