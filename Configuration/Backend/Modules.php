<?php

use N9c\Monitor\Controller\MonitorModuleController;
use TYPO3\CMS\Core\Information\Typo3Version;

// Hauptmodul heisst ab TYPO3 14 "admin" (vorher "system"), vgl. EXT:reports.
$parent = (new Typo3Version())->getMajorVersion() >= 14 ? 'admin' : 'system';

return [
    MonitorModuleController::ROUTE => [
        'parent' => $parent,
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/system/n9c-monitor',
        'iconIdentifier' => 'n9c-monitor-module',
        'labels' => [
            'title' => 'LLL:EXT:n9c_monitor/Resources/Private/Language/locallang_mod.xlf:title',
            'shortDescription' => 'LLL:EXT:n9c_monitor/Resources/Private/Language/locallang_mod.xlf:shortDescription',
            'description' => 'LLL:EXT:n9c_monitor/Resources/Private/Language/locallang_mod.xlf:description',
        ],
        'routes' => [
            '_default' => [
                'target' => MonitorModuleController::class . '::handleRequest',
            ],
        ],
    ],
];
