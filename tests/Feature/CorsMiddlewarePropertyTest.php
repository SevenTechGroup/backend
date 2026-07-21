<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Tests basés sur propriétés (style génératif en PHPUnit pur, sans librairie PBT).
 *
 * Property 7 (design.md) — Fail-safe CORS :
 * « Si aucune origine valide n'est configurée, aucune réponse ne porte l'en-tête
 *  Access-Control-Allow-Origin. » (Requirement 1.2)
 *
 * Property 8 (design.md) — Correspondance exacte d'origine :
 * « L'en-tête Access-Control-Allow-Origin n'est émis que pour une origine
 *  correspondant exactement (sensible à la casse) à une entrée de la liste
 *  blanche, et sa valeur est alors identique à l'origine reçue. »
 *  (Requirements 1.3, 1.4)
 *
 * Validates: Requirements 1.2, 1.3, 1.4
 */
class CorsMiddlewarePropertyTest extends TestCase
{
    private const ROUTE = '/api/_test/cors';

    private const HEADER_ALLOW_ORIGIN = 'Access-Control-Allow-Origin';

    /**
     * Nombre d'itérations pour la Property 7 (fail-safe).
     */
    private const ITERATIONS_FAILSAFE = 100;

    /**
     * Nombre d'itérations pour la Property 8 (correspondance exacte).
     */
    private const ITERATIONS_EXACT_MATCH = 150;

    protected function setUp(): void
    {
        parent::setUp();

        // La route ad-hoc vérifie l'enregistrement global du middleware.
        // La configuration `cors.*` est lue au moment de la requête, ce qui
        // permet de surcharger `config()` dans chaque test avant l'appel.
        Route::match(['GET', 'OPTIONS'], self::ROUTE, fn () => response()->json(['ok' => true]));
    }

    /**
     * Property 7 — Fail-safe : liste blanche vide → aucune réponse (GET normal
     * ou préflight OPTIONS) ne porte l'en-tête Access-Control-Allow-Origin,
     * quelle que soit l'origine reçue.
     */
    public function test_property_failsafe_empty_allowlist_never_emits_allow_origin(): void
    {
        $checked = 0;

        for ($i = 0; $i < self::ITERATIONS_FAILSAFE; $i++) {
            $origin = $this->generateRandomOrigin();

            config()->set('cors.allowed_origins', []);

            // Requête normale (GET).
            $get = $this->withHeader('Origin', $origin)->getJson(self::ROUTE);
            $this->assertFalse(
                $get->headers->has(self::HEADER_ALLOW_ORIGIN),
                'Property 7 violée (GET) : Allow-Origin émis avec une liste blanche vide. '
                .$this->describe([], $origin),
            );

            // Préflight (OPTIONS).
            $options = $this->withHeader('Origin', $origin)->json('OPTIONS', self::ROUTE);
            $this->assertFalse(
                $options->headers->has(self::HEADER_ALLOW_ORIGIN),
                'Property 7 violée (OPTIONS) : Allow-Origin émis avec une liste blanche vide. '
                .$this->describe([], $origin),
            );

            $checked++;
        }

        $this->assertSame(self::ITERATIONS_FAILSAFE, $checked);
    }

    /**
     * Property 7 — graines fixes : origines qui, si un joker existait, seraient
     * les plus susceptibles de fuiter (localhost, null, origines plausibles).
     */
    public function test_property_failsafe_holds_for_fixed_edge_seeds(): void
    {
        $seeds = [
            'https://app.example.tld',
            'http://localhost:3000',
            'https://localhost',
            'null',
            'http://127.0.0.1:8080',
            'https://sub.domain.example.com:443',
        ];

        foreach ($seeds as $origin) {
            config()->set('cors.allowed_origins', []);

            $get = $this->withHeader('Origin', $origin)->getJson(self::ROUTE);
            $this->assertFalse(
                $get->headers->has(self::HEADER_ALLOW_ORIGIN),
                'Property 7 violée (GET, seed) : '.$this->describe([], $origin),
            );

            $options = $this->withHeader('Origin', $origin)->json('OPTIONS', self::ROUTE);
            $this->assertFalse(
                $options->headers->has(self::HEADER_ALLOW_ORIGIN),
                'Property 7 violée (OPTIONS, seed) : '.$this->describe([], $origin),
            );
        }
    }

    /**
     * Property 8 — l'en-tête Allow-Origin est présent si et seulement si
     * l'origine reçue est un membre exact (sensible à la casse) de la liste
     * blanche, et sa valeur est alors byte-identique à l'origine reçue.
     */
    public function test_property_allow_origin_present_iff_exact_member(): void
    {
        $checked = 0;

        for ($i = 0; $i < self::ITERATIONS_EXACT_MATCH; $i++) {
            $allowlist = $this->generateRandomAllowlist();
            $origin = $this->pickRequestOrigin($allowlist);

            $this->assertExactMatchInvariant($allowlist, $origin);

            $checked++;
        }

        $this->assertSame(self::ITERATIONS_EXACT_MATCH, $checked);
    }

    /**
     * Property 8 — graines fixes couvrant les cas limites : différence de casse,
     * slash final, port différent, liste vide, membre exact.
     */
    public function test_property_exact_match_holds_for_fixed_edge_seeds(): void
    {
        $base = 'https://app.example.tld';

        $cases = [
            // [allowlist, origin]
            [[$base], $base],                                   // membre exact → présent
            [[$base], 'https://APP.example.tld'],               // casse différente → absent
            [[$base], 'https://app.example.tld/'],              // slash final → absent
            [[$base], 'https://app.example.tld:8443'],          // port différent → absent
            [[], $base],                                        // liste vide → absent
            [[$base], 'http://app.example.tld'],                // schéma différent → absent
            [['http://localhost:3000', $base], 'http://localhost:3000'], // membre exact parmi plusieurs
            [['http://localhost:3000', $base], 'http://localhost:3001'], // near-miss de port → absent
            [[$base], 'https://app.example.tld '],              // espace final → absent
            [[$base], 'HTTPS://app.example.tld'],               // schéma en casse haute → absent
        ];

        foreach ($cases as [$allowlist, $origin]) {
            $this->assertExactMatchInvariant($allowlist, $origin);
        }
    }

    /**
     * Vérifie l'invariant de Property 8 pour une paire (liste blanche, origine),
     * pour la requête normale (GET) comme pour le préflight (OPTIONS).
     */
    private function assertExactMatchInvariant(array $allowlist, string $origin): void
    {
        config()->set('cors.allowed_origins', $allowlist);

        $isExactMember = in_array($origin, $allowlist, true);
        $context = $this->describe($allowlist, $origin);

        foreach (['GET', 'OPTIONS'] as $method) {
            $response = $method === 'GET'
                ? $this->withHeader('Origin', $origin)->getJson(self::ROUTE)
                : $this->withHeader('Origin', $origin)->json('OPTIONS', self::ROUTE);

            $present = $response->headers->has(self::HEADER_ALLOW_ORIGIN);

            // Présent si et seulement si membre exact.
            $this->assertSame(
                $isExactMember,
                $present,
                "Property 8 violée ({$method}) : Allow-Origin "
                .($present ? 'présent' : 'absent')
                .' alors que le membre exact est '.($isExactMember ? 'attendu' : 'inattendu').". {$context}",
            );

            // Quand présent, sa valeur est byte-identique à l'origine reçue.
            if ($present) {
                $this->assertSame(
                    $origin,
                    $response->headers->get(self::HEADER_ALLOW_ORIGIN),
                    "Property 8 violée ({$method}) : la valeur d'Allow-Origin diffère de l'origine reçue. {$context}",
                );
            }
        }
    }

    /**
     * Choisit une origine de requête pour Property 8 : soit un membre exact de
     * la liste blanche, soit un near-miss/non-membre.
     */
    private function pickRequestOrigin(array $allowlist): string
    {
        // ~40 % de chances de viser un membre exact (si la liste n'est pas vide).
        if ($allowlist !== [] && random_int(0, 9) < 4) {
            return $allowlist[array_rand($allowlist)];
        }

        // Sinon : near-miss d'un membre existant, ou origine totalement aléatoire.
        if ($allowlist !== [] && random_int(0, 1) === 0) {
            return $this->nearMiss($allowlist[array_rand($allowlist)]);
        }

        return $this->generateRandomOrigin();
    }

    /**
     * Construit un near-miss d'une origine : variante de casse, slash final,
     * port modifié/ajouté, ou schéma modifié. Garantit une chaîne différente.
     */
    private function nearMiss(string $origin): string
    {
        $variant = match (random_int(0, 4)) {
            0 => strtoupper($origin),                                  // casse haute
            1 => $origin.'/',                                          // slash final
            2 => $origin.':'.random_int(1, 65535),                    // port ajouté
            3 => str_starts_with($origin, 'https://')                 // schéma inversé
                ? 'http://'.substr($origin, 8)
                : 'https://'.substr($origin, 7),
            default => $origin.' ',                                    // espace final
        };

        // Si par malchance la variante est identique (ex. déjà en majuscules),
        // on force une différence non ambiguë.
        return $variant === $origin ? $origin.'.near-miss' : $variant;
    }

    /**
     * Génère une liste blanche aléatoire de 0 à 5 origines distinctes.
     *
     * @return array<int, string>
     */
    private function generateRandomAllowlist(): array
    {
        $count = random_int(0, 5);
        $origins = [];

        while (count($origins) < $count) {
            $candidate = $this->generateRandomOrigin();
            if (! in_array($candidate, $origins, true)) {
                $origins[] = $candidate;
            }
        }

        return $origins;
    }

    /**
     * Génère une origine aléatoire de la forme https://<host> ou
     * http://<host>:<port>, avec des variantes de casse possibles sur l'hôte.
     */
    private function generateRandomOrigin(): string
    {
        $scheme = random_int(0, 1) === 0 ? 'https' : 'http';
        $host = $this->randomHost();

        // Casse mixte occasionnelle sur l'hôte (pertinent pour la sensibilité à la casse).
        if (random_int(0, 3) === 0) {
            $host = $this->flipCase($host);
        }

        // Port optionnel.
        if (random_int(0, 1) === 0) {
            return sprintf('%s://%s:%d', $scheme, $host, random_int(1, 65535));
        }

        return sprintf('%s://%s', $scheme, $host);
    }

    /**
     * Génère un hôte de type domaine (labels alphanumériques séparés par des points).
     */
    private function randomHost(): string
    {
        $labelCount = random_int(1, 3);
        $labels = [];

        for ($i = 0; $i < $labelCount; $i++) {
            $labels[] = $this->randomLabel(random_int(1, 12));
        }

        $tlds = ['com', 'tld', 'io', 'net', 'org', 'app', 'dev', 'example'];
        $labels[] = $tlds[random_int(0, count($tlds) - 1)];

        return implode('.', $labels);
    }

    /**
     * Génère un label DNS (lettres et chiffres minuscules).
     */
    private function randomLabel(int $length): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * Inverse la casse de chaque caractère alphabétique d'une chaîne.
     */
    private function flipCase(string $value): string
    {
        $out = '';

        foreach (str_split($value) as $char) {
            if (ctype_upper($char)) {
                $out .= strtolower($char);
            } elseif (ctype_lower($char)) {
                $out .= strtoupper($char);
            } else {
                $out .= $char;
            }
        }

        return $out;
    }

    /**
     * Décrit la paire (liste blanche, origine) pour les messages d'échec.
     *
     * @param  array<int, string>  $allowlist
     */
    private function describe(array $allowlist, string $origin): string
    {
        $list = $allowlist === [] ? '(vide)' : '['.implode(', ', $allowlist).']';

        return sprintf('Offending input → allowlist=%s, origin="%s"', $list, $origin);
    }
}
