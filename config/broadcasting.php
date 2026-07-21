<?php

return [
    /*
    | Laravel Cloud injecte les variables REVERB_* lorsqu'un cluster WebSocket
    | est rattaché. En leur absence, l'application reste silencieusement sur le
    | diffuseur null afin de conserver le mode REST local et hors ligne.
    */
    'default' => env(
        'BROADCAST_CONNECTION',
        env('REVERB_APP_KEY') ? 'reverb' : 'null',
    ),

    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],
];
