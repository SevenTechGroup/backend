# ADR 0001 — Transport du jeton JWT via un cookie sécurisé

- **Numéro :** 0001
- **Titre :** Migration du transport du JWT de l'en-tête `Authorization` vers un cookie sécurisé
- **Date :** 2025-06-09
- **Statut :** acceptée
- **Décideurs :** Équipe backend `backend7tech`, architecte de la solution
- **Exigences couvertes :** R5.1, R5.2, R5.3, R5.4, R5.5, R5.6, R5.7

> **Statut : acceptée.** Cette valeur est unique et explicite, choisie parmi
> l'ensemble {proposée, acceptée, rejetée}. La décision entérine la migration du
> transport du JWT vers un cookie sécurisé. (R5.7)

---

## Contexte

Le backend `backend7tech` (Laravel 13, PHP 8.3) authentifie les appels d'API à
l'aide de jetons JWT émis par le paquet `tymon/jwt-auth`. Le garde
d'authentification `api` est configuré avec le driver `jwt`
(`config/auth.php`), et les routes protégées passent par le middleware
`auth:api`.

### Canal de transport actuel

Aujourd'hui, le jeton JWT est transporté via **l'en-tête HTTP
`Authorization: Bearer <token>`** :

- À la connexion, `AuthController::login` (via `AuthService::login`) renvoie le
  jeton **dans le corps JSON de la réponse** (champ `token`). De même,
  `AuthController::register` renvoie un champ `token` dans le corps de la
  réponse `201`.
- Le client (la PWA) doit donc **lire ce jeton depuis le corps de la réponse**,
  le **stocker côté client** (typiquement `localStorage`, `sessionStorage` ou
  une variable JS), puis le **ré-émettre manuellement** dans l'en-tête
  `Authorization` de chaque requête ultérieure.

Ce canal implique que le jeton est **accessible au JavaScript de la page**
(stockage accessible au client). (R5.1)

### Limite de sécurité associée

- **Exposition au vol par XSS.** Comme le jeton est lisible par le JavaScript
  client (corps de réponse + stockage navigateur), toute faille de type
  Cross-Site Scripting (XSS) permet à un script injecté de **lire et exfiltrer
  le jeton**, puis d'usurper la session de l'utilisateur. Un cookie `HttpOnly`,
  à l'inverse, n'est pas accessible au JavaScript. (R5.1)
- Limite complémentaire : le stockage persistant côté client (p. ex.
  `localStorage`) conserve le jeton au-delà de la session onglet et élargit la
  fenêtre d'exposition.

---

## Options envisagées

### Option A — Transport via l'en-tête `Authorization: Bearer`

Le client conserve le jeton et le renvoie dans l'en-tête `Authorization` de
chaque requête (comportement actuel).

- **Avantage :** Simplicité et absence de vulnérabilité CSRF. L'en-tête étant
  ajouté explicitement par le code JavaScript, il n'est pas envoyé
  automatiquement par le navigateur lors d'une requête cross-site ; le vecteur
  CSRF « classique » (soumission automatique de cookie) ne s'applique pas. Le
  modèle est aussi indépendant du domaine (pratique pour des clients tiers ou
  mobiles). (R5.6)
- **Inconvénient :** Le jeton est accessible au JavaScript (corps de réponse +
  stockage client) et donc **vulnérable au vol via XSS** (exfiltration puis
  usurpation de session). (R5.6)

### Option B — Transport via un cookie sécurisé (`HttpOnly` + `Secure` + `SameSite`)

Le backend dépose le JWT dans un **cookie sécurisé** portant **simultanément**
les trois attributs `HttpOnly`, `Secure` et `SameSite`, et le navigateur le
renvoie automatiquement. (R5.2)

- Attributs du `Cookie_Securise` :
  - **`HttpOnly`** : le cookie est inaccessible au JavaScript (`document.cookie`
    ne le voit pas), ce qui neutralise l'exfiltration par XSS.
  - **`Secure`** : le cookie n'est transmis que sur des connexions HTTPS.
  - **`SameSite`** : contrôle l'envoi du cookie lors des requêtes cross-site
    (voir la section dédiée ci-dessous).
- **Avantage :** Le jeton est **protégé du JavaScript** grâce à `HttpOnly` : même
  en cas de faille XSS, le script injecté ne peut pas lire le jeton, ce qui
  supprime la principale limite de l'Option A. (R5.6)
- **Inconvénient :** Comme le navigateur envoie le cookie automatiquement, ce
  modèle **réintroduit le risque CSRF** et impose donc une **protection CSRF**
  dédiée ; il **complexifie la configuration CORS** (credentials + origine
  explicite, voir plus bas) et le cycle de vie du cookie (dépôt/révocation côté
  serveur). (R5.6)

---

## Attribut `SameSite`

**Valeur retenue : `Strict`** — valeur unique et explicite choisie parmi
l'ensemble {`Strict`, `Lax`, `None`}. (R5.3)

**Justification.** Le frontend (PWA) et l'API partagent le même contexte de
première partie ; aucun scénario métier ne nécessite l'envoi du cookie
d'authentification lors d'une navigation initiée depuis un site tiers.
`SameSite=Strict` offre la protection la plus forte : le navigateur **n'envoie
jamais** le cookie sur une requête provenant d'un autre site, ce qui neutralise
la quasi-totalité des vecteurs CSRF inter-sites. On préfère `Strict` à `Lax`
(qui laisserait passer le cookie sur certaines navigations top-level en `GET`)
et à `None` (qui exposerait le cookie à tout contexte cross-site).

**Clause conditionnelle `None`.** La valeur retenue étant `Strict`, cette clause
n'est **pas déclenchée**. Elle est néanmoins documentée pour référence : **si**
la valeur `None` était choisie (par exemple pour un déploiement où le frontend
et l'API sont sur des sites réellement distincts nécessitant l'envoi
cross-site), **alors l'attribut `Secure` devrait obligatoirement être positionné
à vrai** (`Secure=true`). Les navigateurs modernes rejettent en effet tout
cookie `SameSite=None` qui n'est pas également `Secure`. (R5.4)

---

## Conséquences CORS

Le passage à un cookie sécurisé cross-origin impose des contraintes strictes sur
la configuration CORS du `CorsMiddleware` : (R5.5)

1. **`Access-Control-Allow-Credentials: true` est requis.** Sans cet en-tête, le
   navigateur n'inclura pas le cookie dans les requêtes cross-origin et ne
   laissera pas le JavaScript accéder à la réponse. Côté application, cela
   correspond à la clé de configuration **`cors.supports_credentials`**
   (variable d'environnement `CORS_SUPPORTS_CREDENTIALS`) qui doit être passée à
   `true`. Le `CorsMiddleware` n'émet alors
   `Access-Control-Allow-Credentials: true` **que** pour une origine explicitement
   autorisée.
2. **Origine explicite obligatoire, jamais le joker `*`.** Lorsque les
   credentials sont autorisés, la spécification CORS **interdit** l'usage du
   joker `*` pour `Access-Control-Allow-Origin` : l'en-tête doit renvoyer une
   **origine explicite** issue de la liste blanche. Le `CorsMiddleware` respecte
   déjà cette règle (correspondance exacte, sensible à la casse ; aucun joker
   n'est jamais émis).

En résumé, activer le transport par cookie implique conjointement :
`CORS_SUPPORTS_CREDENTIALS=true` **et** une liste `CORS_ALLOWED_ORIGINS`
renseignée avec les origines explicites du frontend (aucun `*`).

---

## Décision

**Nous adoptons l'Option B :** migrer le transport du JWT de l'en-tête
`Authorization: Bearer` vers un **cookie sécurisé** portant simultanément
`HttpOnly`, `Secure` et `SameSite=Strict`. (R5.7)

### Justification

- La limite de sécurité majeure du transport actuel (jeton lisible par le
  JavaScript, donc exfiltrable par XSS) est **éliminée** par l'attribut
  `HttpOnly`.
- `SameSite=Strict` couvre le besoin réel (frontend et API en première partie)
  tout en neutralisant les vecteurs CSRF inter-sites, sans dégrader
  l'expérience utilisateur.
- Le coût principal — protection CSRF et configuration CORS avec credentials +
  origine explicite — est **maîtrisé** : le `CorsMiddleware` gère déjà les
  origines explicites sans joker, et l'activation des credentials est prévue via
  `cors.supports_credentials`.
- Le bénéfice sécurité (surface d'attaque XSS réduite sur le vol de session)
  l'emporte sur le surcoût d'implémentation.

---

## Conséquences / suites

- **Protection CSRF requise.** Le cookie étant envoyé automatiquement par le
  navigateur, il faut ajouter une protection anti-CSRF (jeton anti-CSRF
  double-submit ou en-tête personnalisé vérifié côté serveur, combiné à
  `SameSite=Strict`).
- **Adaptation du frontend.** La PWA doit **cesser de lire le jeton depuis le
  corps de la réponse** de connexion/inscription et **ne plus le stocker**
  (localStorage/sessionStorage) ni le ré-émettre dans l'en-tête `Authorization` ;
  elle s'appuiera sur l'envoi automatique du cookie par le navigateur.
- **Adaptation du backend.** `AuthController::login` / `register` doivent déposer
  le JWT dans un cookie sécurisé (via `Set-Cookie` avec
  `HttpOnly; Secure; SameSite=Strict`) plutôt que — ou en complément d'une phase
  de transition — de le renvoyer dans le corps JSON ; la lecture du jeton par le
  garde `api` doit être adaptée pour extraire le JWT du cookie.
- **Configuration d'environnement.** En production, positionner
  `CORS_SUPPORTS_CREDENTIALS=true` et renseigner `CORS_ALLOWED_ORIGINS` avec les
  origines explicites du frontend. `Secure=true` impose un service exposé
  exclusivement en HTTPS.
- **Révocation / expiration.** Prévoir la suppression du cookie à la déconnexion
  (`AuthController::logout`) et l'alignement de la durée de vie du cookie sur
  celle du JWT.
