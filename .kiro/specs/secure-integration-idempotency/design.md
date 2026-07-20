# Design Document

## Overview

Cette conception détaille l'implémentation de quatre capacités transverses sur le backend Laravel `backend7tech` (Laravel 13, PHP 8.3, JWT via `tymon/jwt-auth`) :

1. **CORS restreint par liste blanche** — un middleware qui n'autorise que les origines configurées.
2. **Corrélation de requête** — un middleware qui gère l'en-tête `X-Request-ID` et l'injecte dans le contexte de log.
3. **Idempotence des créations de signalement** — un middleware + une table dédiée garantissant qu'une clé `X-Idempotency-Key` ne produit qu'un seul `Report`, avec rejeu de la réponse initiale.
4. **Décision d'architecture (ADR)** — un document formel sur la migration du transport JWT vers un cookie sécurisé.

Le choix directeur est d'implémenter les axes 1 à 3 comme des **middlewares HTTP** enregistrés dans `bootstrap/app.php`, pour rester non-intrusifs vis-à-vis des contrôleurs et services existants (`ReportController`, `ReportService::createReport`). L'idempotence enveloppe uniquement la route `POST /api/reports`.

L'axe 4 est purement documentaire (aucun code applicatif), livré sous forme d'ADR versionné dans le dépôt.

### Objectifs de conception

- Zéro régression sur les endpoints existants (les middlewares sont additifs).
- Sécurité par défaut : aucune origine autorisée si la configuration est absente ou vide.
- Idempotence robuste face à la concurrence (verrou au niveau base de données).
- Traçabilité de bout en bout via un identifiant de corrélation unique.

### Correspondance exigences → composants

| Exigence | Composant principal |
|----------|---------------------|
| R1 (CORS) | `CorsMiddleware` + `config/cors.php` |
| R2 (X-Request-ID) | `RequestIdMiddleware` |
| R3 (persistance idempotence) | `IdempotencyMiddleware` + table `idempotency_keys` + modèle `IdempotencyKey` |
| R4 (rejeu sans doublon) | `IdempotencyMiddleware` (verrou pessimiste + `firstOrCreate`) |
| R5 (décision auth) | `docs/adr/0001-jwt-cookie-transport.md` |

## Architecture

### Pile de middlewares (ordre d'exécution)

Les middlewares sont ajoutés au groupe `api` dans `bootstrap/app.php`. L'ordre est significatif :

```
Requête entrante
   │
   ▼
[1] RequestIdMiddleware   ── résout/génère X-Request-ID, l'attache au contexte de log
   │
   ▼
[2] CorsMiddleware        ── court-circuite les préflights OPTIONS, prépare les en-têtes CORS
   │
   ▼
[3] throttle / auth:api   ── middlewares existants (inchangés)
   │
   ▼
[4] IdempotencyMiddleware ── (uniquement POST /api/reports) verrou + rejeu ou passage
   │
   ▼
Controller (ReportController::store) ── inchangé
   │
   ▼ (réponse remonte la pile)
IdempotencyMiddleware  ── persiste la réponse pour la clé
CorsMiddleware         ── ajoute Access-Control-Allow-* sur la réponse
RequestIdMiddleware    ── ajoute X-Request-ID sur la réponse
```

**Justification de l'ordre :**
- `RequestIdMiddleware` en premier : toutes les entrées de log (y compris CORS/idempotence) doivent porter la corrélation.
- `CorsMiddleware` avant l'authentification : un préflight `OPTIONS` ne doit jamais exiger de jeton.
- `IdempotencyMiddleware` après `auth:api` : la clé est liée à une requête authentifiée, et on évite d'ouvrir un verrou pour un appelant non authentifié.

### Diagramme de flux — idempotence

```
POST /api/reports (X-Idempotency-Key: K)
   │
   ▼
Valider K (présence, 1..128 car.) ── invalide ─▶ 422 (pas de Report)
   │ valide
   ▼
Empreinte du corps = hash(body)
   │
   ▼
Transaction + lock:
  firstOrCreate(idempotency_keys, key=K) ──┐
   │ créé (nouveau)          │ déjà existant │
   ▼                         ▼               │
 status=processing      status=processing ──▶ 409 (traitement en cours)
   │                    status=completed:
   │                       ├─ empreinte identique ─▶ rejouer réponse enregistrée
   │                       └─ empreinte différente ─▶ 422 (conflit de clé)
   ▼
 Exécuter le controller (crée le Report)
   │
   ▼
 Enregistrer status_code + body + empreinte, status=completed
   │
   ▼
 Retourner la réponse (201)
```

## Components and Interfaces

### 1. RequestIdMiddleware

**Fichier :** `app/Http/Middleware/RequestIdMiddleware.php`

**Responsabilité :** résoudre ou générer l'identifiant de corrélation et le propager.

**Logique :**
- Lire l'en-tête `X-Request-ID`. Laravel expose la **première** occurrence via `$request->header('X-Request-ID')` (R2.4 satisfait par défaut).
- `trim()` la valeur.
- Valider avec l'expression régulière `/^[A-Za-z0-9_-]{1,128}$/` :
  - Si valide → réutiliser (R2.1).
  - Si vide/absent → générer `Str::uuid()->toString()` (R2.2).
  - Si trop long ou caractères hors ensemble → générer un UUID v4 (R2.3).
- Stocker l'ID résolu dans `$request->attributes` et dans le contexte de log via `Log::withContext(['request_id' => $id])` (R2.6).
- Après le traitement (`$response`), positionner l'en-tête `X-Request-ID` sur la réponse (R2.5).

**Interface :**
```php
public function handle(Request $request, Closure $next): Response
```

**Constante :** `private const MAX_LENGTH = 128;` et `private const PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';`

### 2. CorsMiddleware

**Fichier :** `app/Http/Middleware/CorsMiddleware.php`
**Configuration :** `config/cors.php` (nouveau)

**Responsabilité :** appliquer la liste blanche d'origines et gérer les préflights.

**Configuration (`config/cors.php`) :**
```php
return [
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ))),
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Request-ID', 'X-Idempotency-Key'],
    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),
];
```

**Logique :**
- Lire `config('cors.allowed_origins')`. Chaque entrée validée : longueur ≤ 253 (R1.1). Les entrées vides/invalides sont ignorées, ce qui donne une liste vide en absence de config (R1.2).
- Déterminer si l'origine de la requête (`Origin` header) correspond **exactement** (sensible à la casse) à une origine autorisée (R1.3).
- **Préflight (`OPTIONS`) :**
  - Origine autorisée → réponse `204` avec `Access-Control-Allow-Origin: <origine>`, `Access-Control-Allow-Methods`, `Access-Control-Allow-Headers` (R1.5).
  - Origine non autorisée → réponse `204` **sans** en-têtes CORS (R1.6).
- **Requête normale :**
  - Origine autorisée → laisser passer puis ajouter `Access-Control-Allow-Origin: <origine>` (R1.3).
  - Origine non autorisée ou absente → laisser passer sans en-tête `Access-Control-Allow-Origin` (R1.4).
- `Access-Control-Allow-Headers` inclut toujours `X-Request-ID` et `X-Idempotency-Key` (R1.7).
- Si `supports_credentials` est vrai ET l'origine est autorisée → ajouter `Access-Control-Allow-Credentials: true` (R1.8). Jamais avec un joker d'origine.

**Note de sécurité :** le joker `*` n'est jamais émis pour `Access-Control-Allow-Origin`. Seules des origines explicites sont renvoyées, condition nécessaire au support des credentials.

### 3. IdempotencyMiddleware

**Fichier :** `app/Http/Middleware/IdempotencyMiddleware.php`
**Alias :** `idempotency` (enregistré dans `bootstrap/app.php`), appliqué à `POST /api/reports`.

**Responsabilité :** garantir qu'une clé produit exactement un `Report` et rejouer la réponse enregistrée.

**Validation de la clé :**
- Lire `X-Idempotency-Key`, `trim()`.
- Absente, vide, ou longueur > 128 → `422` avec message « clé d'idempotence absente ou invalide », sans créer de Report (R3.3, R4.6).

**Empreinte du corps :** `hash('sha256', $request->getContent())` pour détecter les corps divergents (R4.5).

**Séquence transactionnelle :**
```php
return DB::transaction(function () use ($request, $next, $key, $fingerprint) {
    $record = IdempotencyKey::where('key', $key)
        ->lockForUpdate()   // verrou pessimiste — sérialise les requêtes concurrentes (R4.4)
        ->first();

    if ($record !== null) {
        if ($record->isExpired()) {        // > 86400 s (R3.5, R4.8)
            $record->delete();             // traitée comme nouvelle
        } elseif ($record->status === 'processing') {
            abort(409, 'Traitement déjà en cours pour cette clé.'); // R3.7, R4.7
        } elseif ($record->request_fingerprint !== $fingerprint) {
            abort(422, 'Conflit de clé d\'idempotence.');           // R4.5
        } else {
            return response($record->response_body, $record->response_status)
                ->withHeaders(['Content-Type' => 'application/json']); // rejeu R4.1, R4.2
        }
    }

    $record = IdempotencyKey::create([
        'key' => $key,
        'request_fingerprint' => $fingerprint,
        'status' => 'processing',
    ]);

    $response = $next($request);

    $record->update([
        'status' => 'completed',
        'response_status' => $response->getStatusCode(),
        'response_body' => $response->getContent(),
        'report_id' => data_get(json_decode($response->getContent(), true), 'data.id'),
    ]);

    return $response;
});
```

**Concurrence :** la combinaison `lockForUpdate()` + contrainte d'unicité sur `key` sérialise l'accès. La première transaction crée l'enregistrement `processing` ; toute transaction concurrente bloque sur le verrou puis observe soit `processing` (→ 409), soit `completed` (→ rejeu). Cela garantit un unique `Report` (R4.3, R4.4).

**Note :** si la réponse du controller n'est pas un succès (statut ≥ 400), l'enregistrement est supprimé plutôt que marqué `completed`, afin de permettre une nouvelle tentative légitime.

### 4. Modèle & migration IdempotencyKey

**Migration :** `database/migrations/xxxx_create_idempotency_keys_table.php`

```php
Schema::create('idempotency_keys', function (Blueprint $table) {
    $table->id();
    $table->string('key', 128)->unique();              // R3.1 contrainte d'unicité
    $table->string('request_fingerprint', 64);         // sha256 hex
    $table->string('status', 20)->default('processing'); // processing | completed
    $table->unsignedSmallInteger('response_status')->nullable();
    $table->longText('response_body')->nullable();      // R3.4
    $table->foreignId('report_id')->nullable();         // R3.2
    $table->timestamps();
    $table->index('created_at');                        // pour purge par expiration
});
```

**Modèle :** `app/Models/IdempotencyKey.php`
```php
class IdempotencyKey extends Model
{
    protected $fillable = [
        'key', 'request_fingerprint', 'status',
        'response_status', 'response_body', 'report_id',
    ];

    public const TTL_SECONDS = 86400;   // R4.8

    public function isExpired(): bool
    {
        return $this->created_at->diffInSeconds(now()) > self::TTL_SECONDS;
    }
}
```

L'expiration est configurable via `config('idempotency.ttl', 86400)` pour satisfaire R3.5 (« WHERE une durée est configurée ») tout en fixant la valeur par défaut à 86400 s (R4.8).

### 5. ADR — transport du JWT

**Fichier :** `docs/adr/0001-jwt-cookie-transport.md`

Document structuré (format ADR) couvrant R5 :
- **Contexte** : transport actuel via en-tête `Authorization: Bearer` (guard `api` JWT), avec la limite de sécurité que le jeton est accessible au JavaScript client (exposition XSS) (R5.1).
- **Option A — en-tête `Authorization`** : avantage (simplicité, pas de CSRF), inconvénient (vulnérable au vol via XSS) (R5.6).
- **Option B — Cookie_Securise** `HttpOnly` + `Secure` + `SameSite` (R5.2) : avantage (protégé du JavaScript), inconvénient (nécessite protection CSRF, complexité CORS) (R5.6).
- **Valeur `SameSite` retenue** : `Strict` (R5.3). Comme ce n'est pas `None`, la clause conditionnelle R5.4 est documentée mais non déclenchée ; l'ADR note néanmoins que si `None` était choisi, `Secure=true` serait obligatoire (R5.4).
- **Conséquences CORS** : `Access-Control-Allow-Credentials: true` requis et origine explicite obligatoire (pas de `*`) (R5.5).
- **Décision & statut** : décision retenue + justification + statut ∈ {proposée, acceptée, rejetée} (R5.7).

## Data Models

### Table `idempotency_keys`

| Colonne | Type | Contrainte | Rôle |
|---------|------|-----------|------|
| `id` | bigint | PK auto | Identifiant technique |
| `key` | varchar(128) | UNIQUE, NOT NULL | Clé `X-Idempotency-Key` (R3.1) |
| `request_fingerprint` | varchar(64) | NOT NULL | SHA-256 du corps (détection conflit R4.5) |
| `status` | varchar(20) | NOT NULL, défaut `processing` | `processing` \| `completed` |
| `response_status` | smallint | nullable | Code HTTP enregistré (R3.4, R4.2) |
| `response_body` | longtext | nullable | Corps de réponse enregistré (R3.4, R4.2) |
| `report_id` | bigint | nullable | Référence du `Signalement_Initial` (R3.2) |
| `created_at` / `updated_at` | timestamp | | Base du calcul d'expiration (R4.8) |

La contrainte `UNIQUE(key)` est la garantie de dernier recours contre les doublons même en cas de course non couverte par le verrou applicatif.

## Configuration & Variables d'environnement

À ajouter dans `.env.example` :
```
# Origines frontend autorisées (séparées par des virgules), pas de joker.
CORS_ALLOWED_ORIGINS=http://localhost:5173,https://pwa.exemple.tld
# Autoriser les cookies cross-origin (mettre true si transport JWT par cookie).
CORS_SUPPORTS_CREDENTIALS=false
# Durée de vie d'une clé d'idempotence (secondes).
IDEMPOTENCY_TTL=86400
```

Fichiers de config : `config/cors.php` (nouveau) et `config/idempotency.php` (nouveau, expose `ttl`).

## Error Handling

| Situation | Code | Corps | Exigence |
|-----------|------|-------|----------|
| Clé d'idempotence absente/vide/>128 | 422 | message « clé absente ou invalide » | R3.3, R4.6 |
| Clé réutilisée avec corps différent | 422 | message « conflit de clé d'idempotence » | R4.5 |
| Requête concurrente sur clé en cours | 409 | message « traitement en cours » | R3.7, R4.7 |
| Rejeu réussi | code enregistré (201) | corps enregistré | R4.1, R4.2 |
| Préflight origine non autorisée | 204 | vide, sans en-têtes CORS | R1.6 |

Les réponses d'erreur suivent le format JSON existant de l'API (`shouldRenderJsonWhen` déjà configuré pour `api/*` dans `bootstrap/app.php`). On lève les erreurs via `abort(code, message)` / `response()->json(...)` pour rester cohérent avec la gestion d'exceptions Laravel.

## Testing Strategy

Tests via **PHPUnit** (déjà présent). On privilégie des **feature tests** HTTP car les composants sont des middlewares.

### Tests CORS (`tests/Feature/CorsMiddlewareTest.php`)
- Origine autorisée → en-tête `Access-Control-Allow-Origin` égal à l'origine.
- Origine non autorisée → absence de l'en-tête, requête traitée quand même.
- Config vide/absente → aucun en-tête d'origine.
- Préflight `OPTIONS` autorisé → 204 + `Allow-Methods`/`Allow-Headers`.
- Préflight non autorisé → 204 sans en-têtes CORS.
- `Allow-Headers` contient `X-Request-ID` et `X-Idempotency-Key`.
- `supports_credentials` → `Allow-Credentials: true` uniquement avec origine explicite.

### Tests Request-ID (`tests/Feature/RequestIdMiddlewareTest.php`)
- Valeur valide fournie → réutilisée dans la réponse.
- Absente/vide → UUID v4 généré.
- Trop longue / caractères interdits → nouvel UUID v4.
- En-tête présent en réponse.
- Contexte de log enrichi (assertion via un fake de canal de log).

### Tests idempotence (`tests/Feature/IdempotencyTest.php`)
- Clé absente/vide/>128 → 422, aucun `Report` créé.
- Première requête → 201, enregistrement `completed`, `Report` créé.
- Rejeu même clé + même corps → même code + même corps, un seul `Report` en base.
- Même clé + corps différent → 422, `Report` initial inchangé.
- Clé expirée (TTL dépassé, simulée en manipulant `created_at`) → traitée comme nouvelle.
- Concurrence (deux insertions même clé) → une seule persistée ; la contrainte d'unicité et le verrou sont couverts par un test simulant l'état `processing` → 409.

### Tests ADR
- Contrôle documentaire manuel (checklist) : présence des sections exigées par R5.1–R5.7. Non automatisé.

### Property-Based Testing (optionnel)
Pour la validation de la clé et du `X-Request-ID`, des tests basés sur propriétés peuvent générer des chaînes aléatoires afin de vérifier les invariants :
- Toute clé de longueur 1..128 est acceptée ; toute clé vide ou >128 est rejetée (422).
- Tout `X-Request-ID` hors motif `[A-Za-z0-9_-]{1,128}` produit un UUID v4 valide en réponse.

## Correctness Properties

Invariants vérifiables (candidats aux tests basés sur propriétés) :

### Property 1: Unicité du signalement

Pour toute suite de requêtes partageant une même `X-Idempotency-Key` non expirée et un corps identique, le nombre de `Report` persistés est exactement 1.

**Validates: Requirements 4.3, 4.4**

### Property 2: Déterminisme du rejeu

Rejouer une clé `completed` renvoie toujours le même couple (code HTTP, corps) que le premier traitement réussi.

**Validates: Requirements 4.1, 4.2**

### Property 3: Validation de la clé

Une clé est acceptée si et seulement si sa longueur (après `trim`) est dans l'intervalle [1, 128] ; toute autre valeur produit un 422 sans création de `Report`.

**Validates: Requirements 3.3, 4.6**

### Property 4: Détection de conflit

Deux corps de requête d'empreinte SHA-256 différente sous la même clé non expirée produisent toujours un 422 sans modifier le `Signalement_Initial`.

**Validates: Requirements 4.5**

### Property 5: Résolution du Request-ID

Pour toute chaîne d'entrée, l'identifiant retenu est soit la valeur d'entrée (si elle correspond à `^[A-Za-z0-9_-]{1,128}$`), soit un UUID v4 valide ; il n'existe aucun troisième cas.

**Validates: Requirements 2.1, 2.2, 2.3**

### Property 6: Idempotence de la corrélation en réponse

L'en-tête `X-Request-ID` de la réponse est toujours présent et égal à l'identifiant résolu.

**Validates: Requirements 2.5**

### Property 7: Fail-safe CORS

Si aucune origine valide n'est configurée, aucune réponse ne porte l'en-tête `Access-Control-Allow-Origin`.

**Validates: Requirements 1.2**

### Property 8: Correspondance exacte d'origine

L'en-tête `Access-Control-Allow-Origin` n'est émis que pour une origine correspondant exactement (sensible à la casse) à une entrée de la liste blanche, et sa valeur est alors identique à l'origine reçue.

**Validates: Requirements 1.3, 1.4**

### Property 9: Expiration

Une clé dont l'ancienneté dépasse le TTL (86400 s par défaut) est traitée comme absente : la requête suivante est un premier traitement.

**Validates: Requirements 3.5, 4.8**

## Sécurité (rappel)

- Aucune origine autorisée par défaut (fail-safe) — pas d'ouverture accidentelle en production.
- Jamais de joker `*` combiné aux credentials.
- Les secrets (origines, TTL) passent par `.env`, jamais commités ; `.env.example` documente les clés sans valeurs sensibles.
- L'ADR formalise le risque XSS du transport actuel et trace la décision d'évolution.
