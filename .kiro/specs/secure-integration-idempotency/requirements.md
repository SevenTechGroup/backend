# Requirements Document

## Introduction

Cette fonctionnalité vise à sécuriser l'intégration entre la PWA frontend et le backend Laravel (backend7tech) et à garantir l'idempotence des opérations de création. L'objectif est de fermer les écarts backend/frontend qui empêchent le déploiement d'une PWA sûre.

Le périmètre couvre quatre axes :

1. **Contrôle des origines CORS** : n'autoriser que les origines frontend explicitement prévues.
2. **Traçabilité des requêtes** : propager et journaliser un identifiant de corrélation `X-Request-ID`.
3. **Idempotence** : persister une clé `X-Idempotency-Key` avec contrainte d'unicité et rejouer une requête répétée sans créer de doublon (retour du signalement initial).
4. **Décision d'authentification** : documenter une décision formelle sur la migration du jeton JWT vers un cookie `HttpOnly`/`Secure`/`SameSite`.

Le backend utilise actuellement un garde d'authentification `api` basé sur JWT (`auth('api')`), sans configuration CORS personnalisée, et la création de signalements passe par `ReportService::createReport`.

## Glossary

- **Systeme** : Le backend Laravel (backend7tech) exposant l'API REST consommée par la PWA.
- **API** : L'ensemble des points d'accès HTTP sous le préfixe `api/` du Systeme.
- **PWA** : L'application frontend Progressive Web App cliente du Systeme.
- **CORS_Middleware** : Le composant du Systeme qui applique les règles de partage de ressources entre origines (Cross-Origin Resource Sharing).
- **Origine_Autorisee** : Une valeur d'origine HTTP (schéma + hôte + port) figurant dans la liste blanche de configuration du Systeme.
- **Request_ID_Middleware** : Le composant du Systeme qui gère l'identifiant de corrélation de requête.
- **X-Request-ID** : En-tête HTTP contenant l'identifiant de corrélation unique d'une requête.
- **X-Idempotency-Key** : En-tête HTTP fourni par le client contenant une clé d'idempotence unique pour une opération de création.
- **Idempotency_Store** : Le mécanisme de persistance (table de base de données) qui enregistre les clés d'idempotence et les réponses associées.
- **Signalement** : Une entité Report créée via le point d'accès de création de signalements.
- **Signalement_Initial** : Le Signalement créé lors du premier traitement réussi d'une clé d'idempotence donnée.
- **Journal** : Le système de journalisation du Systeme (logging Laravel).
- **Decision_Auth** : Le document de décision d'architecture (ADR) portant sur le mode de transport du jeton d'authentification.
- **Cookie_Securise** : Un cookie HTTP présentant les attributs `HttpOnly`, `Secure` et `SameSite`.

## Requirements

### Requirement 1: Restriction des origines CORS

**User Story:** En tant qu'administrateur de la plateforme, je veux que seules les origines frontend prévues soient autorisées à appeler l'API, afin de réduire la surface d'attaque cross-origine.

#### Acceptance Criteria

1. THE CORS_Middleware SHALL lire la liste des Origine_Autorisee depuis une variable d'environnement de configuration, chaque origine étant une chaîne d'au plus 253 caractères comprenant le schéma, l'hôte et le port optionnel, les origines multiples étant séparées par des virgules.
2. IF la variable d'environnement de configuration des Origine_Autorisee est absente, vide, ou ne contient aucune origine valide, THEN THE CORS_Middleware SHALL traiter la liste des Origine_Autorisee comme vide et omettre l'en-tête `Access-Control-Allow-Origin` de toutes les réponses.
3. WHEN une requête cross-origine provient d'une origine dont la correspondance exacte (schéma, hôte et port identiques, comparaison sensible à la casse) figure dans la liste des Origine_Autorisee, THE CORS_Middleware SHALL inclure l'en-tête `Access-Control-Allow-Origin` contenant exactement la valeur de cette origine dans la réponse.
4. IF une requête cross-origine provient d'une origine absente de la liste des Origine_Autorisee, THEN THE CORS_Middleware SHALL omettre l'en-tête `Access-Control-Allow-Origin` de la réponse et laisser le traitement de la requête se poursuivre sans en-tête CORS d'autorisation.
5. WHEN une requête de pré-vérification (méthode HTTP `OPTIONS`) provient d'une Origine_Autorisee, THE CORS_Middleware SHALL répondre avec le code de statut 204, l'en-tête `Access-Control-Allow-Methods` énumérant les méthodes `GET`, `POST`, `PUT`, `PATCH`, `DELETE` et `OPTIONS`, et l'en-tête `Access-Control-Allow-Headers` correspondant.
6. IF une requête de pré-vérification (méthode HTTP `OPTIONS`) provient d'une origine absente de la liste des Origine_Autorisee, THEN THE CORS_Middleware SHALL répondre avec le code de statut 204 sans inclure les en-têtes `Access-Control-Allow-Origin`, `Access-Control-Allow-Methods` ni `Access-Control-Allow-Headers`.
7. THE CORS_Middleware SHALL inclure les en-têtes `X-Request-ID` et `X-Idempotency-Key` dans la valeur de l'en-tête de réponse `Access-Control-Allow-Headers`.
8. WHERE le transport du jeton d'authentification repose sur un Cookie_Securise, THE CORS_Middleware SHALL positionner l'en-tête `Access-Control-Allow-Credentials` à la valeur `true`.

### Requirement 2: Propagation et journalisation de X-Request-ID

**User Story:** En tant qu'ingénieur d'exploitation, je veux qu'un identifiant de corrélation soit propagé et journalisé pour chaque requête, afin de tracer une requête de bout en bout dans les journaux.

#### Acceptance Criteria

1. WHEN une requête entrante contient un en-tête `X-Request-ID` dont la valeur, après suppression des espaces de début et de fin, comporte de 1 à 128 caractères appartenant exclusivement à l'ensemble alphanumérique, tiret (`-`) et trait de soulignement (`_`), THE Request_ID_Middleware SHALL réutiliser cette valeur comme identifiant de corrélation de la requête.
2. IF une requête entrante ne contient aucun en-tête `X-Request-ID`, ou si la valeur de cet en-tête est vide après suppression des espaces de début et de fin, THEN THE Request_ID_Middleware SHALL générer un identifiant de corrélation au format UUID version 4.
3. IF un en-tête `X-Request-ID` entrant dépasse 128 caractères ou contient au moins un caractère hors de l'ensemble alphanumérique, tiret (`-`) et trait de soulignement (`_`), THEN THE Request_ID_Middleware SHALL générer un nouvel identifiant de corrélation au format UUID version 4.
4. WHEN une requête entrante contient plus d'un en-tête `X-Request-ID`, THE Request_ID_Middleware SHALL retenir uniquement la valeur du premier en-tête `X-Request-ID` pour l'évaluation de l'identifiant de corrélation.
5. THE Request_ID_Middleware SHALL inclure l'identifiant de corrélation retenu dans l'en-tête `X-Request-ID` de la réponse HTTP.
6. THE Request_ID_Middleware SHALL associer l'identifiant de corrélation retenu au contexte du Journal pour toutes les entrées de journal émises pendant le traitement de la requête, du début à la fin de celle-ci.

### Requirement 3: Persistance de la clé d'idempotence avec contrainte d'unicité

**User Story:** En tant que développeur frontend de la PWA, je veux persister une clé d'idempotence unique pour chaque création de signalement, afin d'éviter les doublons lors de renvois réseau.

#### Acceptance Criteria

1. THE Idempotency_Store SHALL persister chaque X-Idempotency-Key avec une contrainte d'unicité rejetant tout enregistrement d'une clé déjà présente.
2. WHEN une requête de création de Signalement contient un en-tête `X-Idempotency-Key` non vide d'une longueur comprise entre 1 et 128 caractères et traité pour la première fois, THE Systeme SHALL enregistrer la clé, l'identifiant du Signalement_Initial créé et la réponse associée dans l'Idempotency_Store.
3. IF une requête de création de Signalement ne contient pas d'en-tête `X-Idempotency-Key`, ou contient un en-tête vide, ou dont la longueur est supérieure à 128 caractères, THEN THE Systeme SHALL répondre avec le code de statut 422, un message d'erreur indiquant que la clé d'idempotence est absente ou invalide, et SHALL ne créer aucun Signalement_Initial.
4. THE Idempotency_Store SHALL associer chaque X-Idempotency-Key au code de statut HTTP et au corps de la réponse renvoyés lors du premier traitement réussi.
5. WHERE une durée d'expiration est configurée pour les clés d'idempotence, THE Systeme SHALL considérer une clé dont l'ancienneté dépasse cette durée comme absente de l'Idempotency_Store lors du traitement d'une nouvelle requête.
6. WHEN une requête de création de Signalement contient un en-tête `X-Idempotency-Key` déjà présent et non expiré dans l'Idempotency_Store, THE Systeme SHALL renvoyer le code de statut HTTP et le corps de réponse enregistrés pour cette clé sans créer de nouveau Signalement_Initial.
7. WHILE une requête portant une X-Idempotency-Key donnée est en cours de traitement, IF une seconde requête portant la même clé est reçue, THEN THE Systeme SHALL rejeter la seconde requête avec le code de statut 409 et un message d'erreur indiquant qu'un traitement pour cette clé est déjà en cours, sans créer de Signalement_Initial supplémentaire.

### Requirement 4: Rejeu idempotent sans doublon

**User Story:** En tant que citoyen utilisant la PWA, je veux qu'un renvoi accidentel de ma demande retourne mon signalement initial, afin de ne pas créer de doublon.

#### Acceptance Criteria

1. WHEN une requête de création de Signalement présente une X-Idempotency-Key déjà présente dans l'Idempotency_Store et associée à un premier traitement réussi, THE Systeme SHALL retourner la réponse enregistrée du Signalement_Initial sans créer de nouveau Signalement.
2. WHEN une requête de création de Signalement présente une X-Idempotency-Key déjà présente dans l'Idempotency_Store et associée à un premier traitement réussi, THE Systeme SHALL retourner le même code de statut HTTP et le même corps de réponse que ceux enregistrés lors du premier traitement réussi.
3. FOR ALL requêtes de création de Signalement partageant une même X-Idempotency-Key et un corps de requête identique, THE Systeme SHALL garantir qu'exactement un Signalement est persisté en base de données.
4. IF deux requêtes ou plus, présentant une même X-Idempotency-Key jamais traitée auparavant et un corps de requête identique, sont traitées de manière concurrente, THEN THE Systeme SHALL persister un seul Signalement et retourner la même réponse à toutes ces requêtes.
5. IF une requête présente une X-Idempotency-Key déjà utilisée avec un corps de requête différent de celui du premier traitement, THEN THE Systeme SHALL répondre avec le code de statut 422, ne créer aucun nouveau Signalement, conserver le Signalement_Initial inchangé, et retourner un message indiquant un conflit de clé d'idempotence.
6. IF une requête de création de Signalement ne présente aucune X-Idempotency-Key, ou présente une X-Idempotency-Key vide ou dépassant 128 caractères, THEN THE Systeme SHALL répondre avec le code de statut 422, ne créer aucun Signalement, et retourner un message indiquant que la clé d'idempotence est absente ou invalide.
7. WHILE une requête portant une X-Idempotency-Key donnée est en cours de traitement et non encore finalisée, THE Systeme SHALL rejeter toute autre requête concurrente présentant la même clé avec le code de statut 409 et un message indiquant qu'un traitement est déjà en cours.
8. WHEN un enregistrement de l'Idempotency_Store atteint une durée de conservation de 86400 secondes depuis son premier traitement, THE Systeme SHALL considérer la X-Idempotency-Key associée comme expirée et traiter toute nouvelle requête portant cette clé comme une première requête.

### Requirement 5: Décision documentée de migration du JWT vers un cookie sécurisé

**User Story:** En tant qu'architecte de la solution, je veux une décision documentée sur la migration du transport du JWT vers un cookie sécurisé, afin de guider l'évolution de l'authentification de la PWA.

#### Acceptance Criteria

1. THE Decision_Auth SHALL documenter le mode de transport actuel du jeton JWT du Systeme en identifiant le canal utilisé (en-tête HTTP `Authorization` ou stockage accessible au client) ainsi qu'au moins une limite de sécurité associée.
2. THE Decision_Auth SHALL documenter l'option de transport du jeton JWT via un Cookie_Securise portant simultanément les attributs `HttpOnly`, `Secure` et `SameSite`.
3. THE Decision_Auth SHALL énoncer une valeur explicite et unique pour l'attribut `SameSite` du Cookie_Securise, choisie parmi exactement une des valeurs de l'ensemble {`Strict`, `Lax`, `None`}.
4. IF la valeur retenue pour l'attribut `SameSite` du Cookie_Securise est `None`, THEN THE Decision_Auth SHALL documenter l'obligation de positionner l'attribut `Secure` à vrai.
5. THE Decision_Auth SHALL documenter les conséquences de la décision sur la configuration CORS, en énonçant l'exigence de positionner l'en-tête `Access-Control-Allow-Credentials` à `true` et l'exigence d'une origine explicite (interdiction du joker `*`) pour l'en-tête `Access-Control-Allow-Origin`.
6. THE Decision_Auth SHALL documenter, pour chacune des deux options de transport (en-tête `Authorization` et Cookie_Securise), au moins un avantage et au moins un inconvénient.
7. THE Decision_Auth SHALL enregistrer la décision retenue, sa justification et son statut correspondant à exactement une valeur de l'ensemble {proposée, acceptée, rejetée}.
