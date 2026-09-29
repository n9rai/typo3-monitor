<?php

declare(strict_types=1);

namespace N9c\Monitor\Controller;

use N9c\Monitor\Service\AutoReport;
use N9c\Monitor\Service\Collector;
use N9c\Monitor\Service\ConfigStore;
use N9c\Monitor\Service\ConnectService;
use N9c\Monitor\Service\NotConnectedException;
use N9c\Monitor\Service\ReportService;
use N9c\Monitor\Service\ReportStamp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Backend-Modul "N9C Inside Monitor" (nur Admins): Status, letzte
 * Auswertung mit offenen Befunden, Datenvorschau, Jetzt senden, Verbinden
 * per Code - alles ohne SSH.
 *
 * Aktionen ueber den Parameter "action": index (Standard), preview,
 * send (POST), connect (POST). POST-Aktionen leiten danach auf index um
 * (Post/Redirect/Get), Rueckmeldungen als Flash-Message.
 */
final class MonitorModuleController
{
    public const ROUTE = 'n9c_monitor';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly ReportService $reportService,
        private readonly ConnectService $connectService,
        private readonly ConfigStore $configStore,
        private readonly ReportStamp $stamp,
        private readonly AutoReport $autoReport,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $action = (string)(is_array($body) && isset($body['action']) ? $body['action'] : ($request->getQueryParams()['action'] ?? 'index'));
        $isPost = $request->getMethod() === 'POST';

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('N9C Inside Monitor');

        if ($isPost && $action === 'send') {
            return $this->sendAction($view);
        }
        if ($isPost && $action === 'connect') {
            return $this->connectAction($view, is_array($body) ? $body : []);
        }
        if ($action === 'preview') {
            return $this->previewAction($view);
        }
        return $this->indexAction($view);
    }

    private function indexAction(ModuleTemplate $view): ResponseInterface
    {
        $config = $this->configStore->load();
        $last = $this->reportService->lastResult();
        $lastSent = $this->stamp->lastSentAt();
        $interval = $this->autoReport->intervalSeconds();

        $dashboard = $this->configStore->dashboardUrl();
        $view->assignMultiple([
            'dashboard' => [
                'base' => $dashboard,
                'register' => $dashboard . '/dashboard/register',
                'login' => $dashboard . '/dashboard/',
                'newCode' => $dashboard . '/dashboard/tokens/new',
                'billing' => $dashboard . '/dashboard/billing',
                'instance' => !empty($config['instance_id']) ? $dashboard . '/dashboard/instances/' . rawurlencode((string)$config['instance_id']) : null,
            ],
            'plan' => is_array($last['plan'] ?? null) ? $last['plan'] : null,
            'dormant' => is_array($last) && (int)($last['status'] ?? 0) === 402,
            'agentVersion' => Collector::AGENT_VERSION,
            'connected' => !empty($config['instance_id']) && !empty($config['secret']),
            'instanceId' => $config['instance_id'],
            'endpoint' => $config['endpoint'],
            'configFromEnv' => $config['source'] === 'env',
            'configFile' => $this->configStore->getFilePath(),
            'autoEnabled' => $this->autoReport->isEnabled(),
            'intervalHours' => intdiv($interval, 3600),
            'nextDue' => $lastSent === null ? null : $lastSent + $interval,
            'last' => $last,
            'lastOk' => is_array($last) && (int)($last['status'] ?? 0) === 200,
            'findings' => is_array($last['findings'] ?? null) ? $last['findings'] : [],
            'hasFindingsInfo' => is_array($last) && array_key_exists('findings', $last) && $last['findings'] !== null,
            'uris' => [
                'index' => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE),
                'preview' => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, ['action' => 'preview']),
                'post' => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE),
            ],
        ]);
        return $view->renderResponse('Monitor/Index');
    }

    private function previewAction(ModuleTemplate $view): ResponseInterface
    {
        $report = $this->reportService->buildReport(true);
        $view->assignMultiple([
            'json' => json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'bytes' => strlen((string)json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'uris' => ['index' => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE)],
        ]);
        return $view->renderResponse('Monitor/Preview');
    }

    private function sendAction(ModuleTemplate $view): ResponseInterface
    {
        try {
            $result = $this->reportService->send('backend');
            $data = $result['data'];
            if ($result['status'] === 200) {
                $counts = $data['counts'] ?? [];
                $view->addFlashMessage(
                    sprintf('Score %s - kritisch %d, mittel %d, ok %d', $data['score'] ?? '?', $counts['critical'] ?? 0, $counts['medium'] ?? 0, $counts['ok'] ?? 0),
                    'Report angenommen',
                    ContextualFeedbackSeverity::OK
                );
            } elseif (in_array($result['status'], [409, 429], true)) {
                $view->addFlashMessage((string)($data['detail'] ?? 'Bitte eine Minute warten.'), 'Report übersprungen', ContextualFeedbackSeverity::INFO);
            } else {
                $view->addFlashMessage('HTTP ' . $result['status'] . ': ' . (is_string($data['detail'] ?? null) ? $data['detail'] : json_encode($data, JSON_UNESCAPED_UNICODE)), 'Report abgelehnt', ContextualFeedbackSeverity::ERROR);
            }
        } catch (NotConnectedException $e) {
            $view->addFlashMessage($e->getMessage(), 'Nicht verbunden', ContextualFeedbackSeverity::WARNING);
        } catch (\Throwable $e) {
            $view->addFlashMessage($e->getMessage(), 'Senden fehlgeschlagen', ContextualFeedbackSeverity::ERROR);
        }
        return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute(self::ROUTE));
    }

    private function connectAction(ModuleTemplate $view, array $body): ResponseInterface
    {
        $result = $this->connectService->connect(
            (string)($body['code'] ?? ''),
            '',
            !empty($body['force'])
        );
        $view->addFlashMessage(
            $result['message'],
            $result['ok'] ? 'Verbindung hergestellt' : 'Verbinden nicht möglich',
            $result['ok'] ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::ERROR
        );
        return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute(self::ROUTE));
    }
}
