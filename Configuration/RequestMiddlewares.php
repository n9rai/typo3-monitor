<?php

// Automatischer Report ohne Cronjob, siehe Classes/Service/AutoReport.php.
// Weit aussen im Stack (direkt nach normalized-params-attribute, das es in
// TYPO3 12-14 im Frontend und Backend gibt): So sieht die Middleware jede
// Anfrage - auch 404-Seiten, Weiterleitungen und eID-Aufrufe, die TYPO3
// beantwortet, bevor innere Middlewares erreicht werden. Sie wird erst nach
// $handler->handle() aktiv und veraendert weder Anfrage noch Antwort.
return [
    'frontend' => [
        'n9c/monitor/auto-report' => [
            'target' => \N9c\Monitor\Middleware\AutoReportMiddleware::class,
            'after' => ['typo3/cms-core/normalized-params-attribute'],
            'before' => ['typo3/cms-frontend/eid'],
        ],
    ],
    'backend' => [
        'n9c/monitor/auto-report' => [
            'target' => \N9c\Monitor\Middleware\AutoReportMiddleware::class,
            'after' => ['typo3/cms-core/normalized-params-attribute'],
            'before' => ['typo3/cms-backend/locked-backend'],
        ],
    ],
];
