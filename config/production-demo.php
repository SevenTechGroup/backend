<?php

return [
    'accounts' => [
        'manager' => [
            'name' => 'Aminata Ndiaye',
            'email' => 'manager.demo@sahelsignal.test',
            'password' => env('PRODUCTION_DEMO_MANAGER_PASSWORD'),
        ],
        'intervenant' => [
            'name' => 'Moussa Diop',
            'email' => 'intervenant.demo@sahelsignal.test',
            'password' => env('PRODUCTION_DEMO_INTERVENANT_PASSWORD'),
        ],
        'citizen_1' => [
            'name' => 'Fatou Fall',
            'email' => 'citoyen1.demo@sahelsignal.test',
            'password' => env('PRODUCTION_DEMO_CITIZEN_1_PASSWORD'),
        ],
        'citizen_2' => [
            'name' => 'Ibrahima Sow',
            'email' => 'citoyen2.demo@sahelsignal.test',
            'password' => env('PRODUCTION_DEMO_CITIZEN_2_PASSWORD'),
        ],
        'citizen_3' => [
            'name' => 'Awa Ba',
            'email' => 'citoyen3.demo@sahelsignal.test',
            'password' => env('PRODUCTION_DEMO_CITIZEN_3_PASSWORD'),
        ],
    ],
];
