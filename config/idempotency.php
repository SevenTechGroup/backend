<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Durée de vie d'une clé d'idempotence (secondes)
    |--------------------------------------------------------------------------
    |
    | Au-delà de cette ancienneté, une clé X-Idempotency-Key est considérée
    | comme expirée : toute nouvelle requête portant cette clé est traitée
    | comme un premier traitement. Valeur par défaut : 86400 s (24 h).
    |
    */

    'ttl' => (int) env('IDEMPOTENCY_TTL', 86400),

];
