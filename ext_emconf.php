<?php

// Fuer TYPO3 12/13 im klassischen Modus. Ab TYPO3 14 werden Version und
// Metadaten aus composer.json gelesen (version + providesPackages).
$EM_CONF[$_EXTKEY] = [
    'title' => 'N9C Inside Monitor',
    'description' => 'Meldet sicherheitsrelevante Kennzahlen dieser TYPO3-Instanz an den N9C Monitoring-Dienst. Kostenloses Konto: https://dashboard.n9c.io/dashboard/register',
    'category' => 'services',
    'author' => 'Jochen Anglett',
    'author_company' => 'N9 Robotics GmbH ⇢ N9C.IO',
    'state' => 'beta',
    'version' => '0.3.3',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-14.99.99',
        ],
        'suggests' => [
            'scheduler' => '',
        ],
    ],
    'autoload' => [
        'psr-4' => ['N9c\\Monitor\\' => 'Classes/'],
    ],
];
