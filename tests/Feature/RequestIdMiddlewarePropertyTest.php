<?php

namespace Tests\Feature;

use App\Http\Middleware\RequestIdMiddleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Test basé sur propriété (style génératif en PHPUnit pur, sans librairie PBT).
 *
 * Property 5 (design.md) — Résolution du Request-ID :
 * « Pour toute chaîne d'entrée, l'identifiant retenu est soit la valeur d'entrée
 *  (si elle correspond à ^[A-Za-z0-9_-]{1,128}$), soit un UUID v4 valide ; il
 *  n'existe aucun troisième cas. »
 *
 * Validates: Requirements 2.1, 2.2, 2.3
 */
class RequestIdMiddlewarePropertyTest extends TestCase
{
    private const HEADER = 'X-Request-ID';

    private const ROUTE = '/_test/request-id';

    /**
     * Motif d'un X-Request-ID valide, identique à celui du middleware.
     */
    private const VALID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    /**
     * Motif strict d'un UUID version 4 (RFC 4122).
     */
    private const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * Nombre d'itérations aléatoires générées pour le test de propriété.
     */
    private const ITERATIONS = 250;

    protected function setUp(): void
    {
        parent::setUp();

        // Le middleware n'est pas encore enregistré globalement (tâche 6) :
        // on définit une route ad-hoc enveloppée par le middleware sous test.
        Route::middleware(RequestIdMiddleware::class)
            ->get(self::ROUTE, fn () => response()->json(['ok' => true]));
    }

    /**
     * Property 5 — l'invariant tient pour un large échantillon d'entrées
     * aléatoires couvrant des alphabets divers.
     */
    public function test_property_resolved_id_is_input_or_uuid_v4_for_random_inputs(): void
    {
        $checked = 0;

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $input = $this->generateRandomInput();
            $this->assertInvariantHolds($input);
            $checked++;
        }

        // Garantit que la boucle a bien exercé le nombre d'itérations prévu.
        $this->assertSame(self::ITERATIONS, $checked);
    }

    /**
     * Graines fixes garantissant la couverture des cas limites,
     * indépendamment de l'aléa.
     */
    public function test_property_holds_for_fixed_edge_case_seeds(): void
    {
        $seeds = [
            '',                             // vide → UUID
            ' ',                            // un espace → UUID
            "\t\n  ",                       // espaces variés → UUID
            'a',                            // 1 caractère valide → réutilisé
            '0',                            // 1 chiffre valide → réutilisé
            '_',                            // underscore seul → réutilisé
            '-',                            // tiret seul → réutilisé
            str_repeat('a', 128),           // exactement 128 → réutilisé
            str_repeat('a', 129),           // exactement 129 → UUID
            str_repeat('x', 300),           // largement trop long → UUID
            'inva lid',                     // espace interne → UUID
            'e#mail',                       // symbole → UUID
            'slash/here',                   // slash → UUID
            'accentué',                     // unicode → UUID
            '☃snowman',                     // unicode non-ASCII → UUID
            '  valid_core  ',               // espaces autour d'un cœur valide → réutilisé (trim)
            '  a b  ',                      // trim laisse un espace interne → UUID
            'MiXeD-Case_123',               // casse mixte valide → réutilisé
            'ABCDEF-abcdef_0123456789',     // valide → réutilisé
            "\0null",                       // caractère de contrôle → UUID
        ];

        foreach ($seeds as $seed) {
            $this->assertInvariantHolds($seed);
        }
    }

    /**
     * Envoie la requête avec l'entrée donnée et vérifie l'invariant de Property 5 :
     *  - si trim(entrée) correspond au motif valide → l'en-tête retourné == trim(entrée) ;
     *  - sinon → l'en-tête retourné est un UUID v4 valide ;
     *  - dans tous les cas l'en-tête est présent, non vide, et satisfait exactement une branche.
     */
    private function assertInvariantHolds(string $input): void
    {
        $response = $this->withHeader(self::HEADER, $input)->getJson(self::ROUTE);

        $context = 'Entrée (offending input) : '.$this->describe($input);

        $response->assertOk();

        $returned = $response->headers->get(self::HEADER);

        // L'en-tête est toujours présent et non vide (aucun troisième cas « vide »).
        $this->assertNotNull($returned, "En-tête X-Request-ID absent. {$context}");
        $this->assertNotSame('', $returned, "En-tête X-Request-ID vide. {$context}");

        $trimmed = trim($input);
        $isValidInput = preg_match(self::VALID_PATTERN, $trimmed) === 1;

        $matchesInput = ($returned === $trimmed);
        $matchesUuid = (Str::isUuid($returned) && preg_match(self::UUID_V4_PATTERN, $returned) === 1);

        if ($isValidInput) {
            $this->assertTrue(
                $matchesInput,
                "Entrée valide : l'identifiant retourné [{$returned}] devrait être égal à trim(entrée) [{$trimmed}]. {$context}",
            );
        } else {
            $this->assertTrue(
                $matchesUuid,
                "Entrée invalide : l'identifiant retourné [{$returned}] devrait être un UUID v4 valide. {$context}",
            );
        }

        // Aucun troisième cas : l'identifiant satisfait exactement une des deux branches.
        $this->assertTrue(
            $matchesInput xor $matchesUuid,
            "Troisième cas détecté : l'identifiant [{$returned}] ne satisfait pas exactement une branche "
            .'(matchesInput='.var_export($matchesInput, true).', matchesUuid='.var_export($matchesUuid, true)."). {$context}",
        );
    }

    /**
     * Génère une entrée aléatoire dans l'une des familles ciblées :
     * chaîne valide de longueur 1..128, vide, espaces seuls, trop longue (129..300),
     * ou contenant des caractères hors [A-Za-z0-9_-] (espaces, symboles, unicode, contrôle).
     */
    private function generateRandomInput(): string
    {
        return match (random_int(0, 6)) {
            0 => $this->randomValidString(random_int(1, 128)),          // valide
            1 => '',                                                    // vide
            2 => str_repeat(' ', random_int(1, 8)),                     // espaces seuls
            3 => $this->randomValidString(random_int(129, 300)),        // trop long (motif valide mais > 128)
            4 => $this->randomStringWithInvalidChars(),                 // caractères interdits
            5 => $this->randomUnicodeString(),                          // unicode non-ASCII
            default => $this->randomPrintableSoup(),                    // mélange arbitraire imprimable/contrôle
        };
    }

    /**
     * Chaîne composée exclusivement de caractères de l'ensemble valide.
     */
    private function randomValidString(int $length): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * Chaîne majoritairement valide mais contenant au moins un caractère interdit.
     */
    private function randomStringWithInvalidChars(): string
    {
        $invalid = [' ', '@', '#', '/', '\\', '.', '!', '*', '+', '=', ':', ';', '(', ')', '%', '&', '?', ',', '<', '>'];
        $base = $this->randomValidString(random_int(1, 30));
        $char = $invalid[random_int(0, count($invalid) - 1)];
        $pos = random_int(0, strlen($base));

        return substr($base, 0, $pos).$char.substr($base, $pos);
    }

    /**
     * Chaîne contenant des caractères unicode non-ASCII.
     */
    private function randomUnicodeString(): string
    {
        $chars = ['é', 'ñ', 'ü', '中', 'あ', '☃', '€', '🚀', 'Ω', 'ß'];
        $len = random_int(1, 10);
        $out = '';

        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, count($chars) - 1)];
        }

        return $out;
    }

    /**
     * Mélange arbitraire de caractères imprimables ASCII (dont espaces et symboles).
     */
    private function randomPrintableSoup(): string
    {
        $len = random_int(1, 40);
        $out = '';

        for ($i = 0; $i < $len; $i++) {
            $out .= chr(random_int(32, 126));
        }

        return $out;
    }

    /**
     * Décrit une entrée de manière lisible pour les messages d'échec
     * (les caractères non imprimables sont échappés).
     */
    private function describe(string $input): string
    {
        $escaped = addcslashes($input, "\0..\37");

        return sprintf('longueur=%d, valeur="%s"', strlen($input), $escaped);
    }
}
