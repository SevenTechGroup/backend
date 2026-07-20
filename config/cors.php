<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chemins gérés par le middleware natif HandleCors (désactivé)
    |--------------------------------------------------------------------------
    |
    | Laravel enregistre par défaut le middleware natif `HandleCors` dans la
    | pile globale. Ce dernier ne s'active QUE pour les chemins listés ici
    | (via `cors.paths`) : une liste vide garantit qu'il court-circuite sans
    | émettre aucun en-tête Access-Control-*. C'est notre `CorsMiddleware`
    | applicatif qui fait autorité sur le CORS. On fixe explicitement `paths`
    | à [] pour éviter tout double traitement des en-têtes CORS.
    |
    */

    'paths' => [],

    /*
    |--------------------------------------------------------------------------
    | Origines autorisées (liste blanche)
    |--------------------------------------------------------------------------
    |
    | Liste des origines frontend explicitement autorisées à appeler l'API.
    | Elle est parsée depuis la variable d'environnement CORS_ALLOWED_ORIGINS
    | (origines séparées par des virgules). Les espaces sont supprimés et les
    | entrées vides filtrées. En l'absence de configuration, la liste est vide
    | (sécurité par défaut : aucune origine autorisée, jamais de joker « * »).
    |
    */

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Méthodes autorisées
    |--------------------------------------------------------------------------
    */

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    /*
    |--------------------------------------------------------------------------
    | En-têtes autorisés
    |--------------------------------------------------------------------------
    |
    | Inclut X-Request-ID (corrélation) et X-Idempotency-Key (idempotence).
    |
    */

    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Request-ID', 'X-Idempotency-Key'],

    /*
    |--------------------------------------------------------------------------
    | Support des credentials
    |--------------------------------------------------------------------------
    |
    | À activer (true) uniquement si le transport du JWT repose sur un cookie
    | sécurisé cross-origin. Ne doit jamais être combiné à un joker d'origine.
    |
    */

    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),

];
