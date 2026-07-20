# Sahel Signal — API backend

Backend Laravel 13 avec PostgreSQL et authentification JWT pour le MVP Sahel Signal.

## Prérequis

- PHP 8.3+
- Composer 2.6+
- PostgreSQL 15+
- extensions PHP `pdo_pgsql`, `mbstring`, `openssl`, `xml` et `ctype`

## Installation

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret --force
php artisan migrate --seed
php artisan serve --port=8000
```

Configurer au préalable dans `.env` les variables `DB_HOST`, `DB_PORT`,
`DB_DATABASE`, `DB_USERNAME` et `DB_PASSWORD`. Ne jamais versionner `.env`,
`APP_KEY` ou `JWT_SECRET`.

Vérification rapide :

```bash
curl -H "Accept: application/json" http://localhost:8000/up
curl -H "Accept: application/json" http://localhost:8000/api/categories
curl -H "Accept: application/json" http://localhost:8000/api/territories
```

## API

L’API expose 16 routes :

| Méthode | Route | Accès |
|---|---|---|
| POST | `/api/auth/register` | Public, limité à 10 requêtes/minute |
| POST | `/api/auth/login` | Public, limité à 10 requêtes/minute |
| POST | `/api/auth/logout` | JWT |
| GET | `/api/auth/me` | JWT |
| GET | `/api/categories` | Public |
| GET | `/api/territories` | Public |
| GET | `/api/reports` | Citoyen : les siens ; agent : affectés ; manager : tous |
| POST | `/api/reports` | JWT |
| GET | `/api/reports/{report}` | Propriétaire ou personnel autorisé |
| PUT | `/api/reports/{report}` | Agent affecté ou manager |
| GET | `/api/assignments` | Agent : les siennes ; manager : toutes |
| POST | `/api/assignments` | Manager |
| PUT | `/api/assignments/{assignment}` | Agent affecté ou manager |
| GET | `/api/notifications` | Notifications de l’utilisateur courant |
| POST | `/api/notifications/{notification}/read` | Propriétaire de la notification |
| GET | `/api/dashboard` | Statistiques filtrées selon le rôle |

Toutes les réponses API d’erreur sont au format JSON. Les routes protégées sans
JWT répondent `401`, les actions interdites `403` et les données invalides `422`.

### Valeurs métier

- Priorité d’un signalement : `low`, `medium`, `high`
- Statuts : `received → in_progress → resolved`
- Affectations : `assigned → in_progress → completed`
- Rôles : `citizen`, `agent`, `manager`

L’inscription publique crée toujours un compte `citizen`.

## Tests

Les tests utilisent exclusivement la base PostgreSQL `sahelsignal_test`.
La suite refuse de démarrer si la base configurée ne se termine pas par `_test`.

Créer cette base une seule fois :

```sql
CREATE DATABASE sahelsignal_test;
```

Puis lancer :

```bash
composer test
```

État vérifié : **26 tests, 151 assertions, 100 % réussis**.

La suite couvre notamment l’authentification, les workflows, les validations,
les transitions, le cloisonnement entre utilisateurs, les rôles, les
notifications, le tableau de bord et la limitation des tentatives de connexion.

## Données de référence

La commande suivante est idempotente :

```bash
php artisan db:seed
```

En environnement local, elle installe 5 catégories, 3 territoires et un jeu de
démonstration complet : 3 utilisateurs, 6 signalements, 4 affectations et 6
notifications. Une seconde exécution met les données à jour sans créer de
doublon.

| Rôle | Identifiant | Mot de passe |
| --- | --- | --- |
| Manager | `manager@sahelsignal.local` | `Manager@2026!` |
| Agent | `agent@sahelsignal.local` | `Agent@2026!` |
| Citoyen | `citoyen@sahelsignal.local` | `Citoyen@2026!` |

Ces comptes sont exclusivement réservés au développement. Le seeder refuse de
s’exécuter en dehors des environnements `local` et `testing`.

## Contrôles qualité

```bash
composer validate --strict
php vendor/bin/pint --test app database routes tests
php artisan test
```

## Sécurité avant production

- utiliser des secrets distincts et non versionnés par environnement ;
- désactiver `APP_DEBUG` en production ;
- terminer la configuration TLS, sauvegardes, supervision et rotation des logs ;
- ne jamais utiliser `sahelsignal_test` pour des données réelles ;
- conserver les policies serveur lors de toute nouvelle route.

Le cœur du MVP est prêt pour l’intégration et la recette. Le passage en
production reste conditionné par la checklist d’infrastructure du
`DEPLOYMENT_GUIDE.md`.
