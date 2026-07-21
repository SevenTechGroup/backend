# Déployer l’API sur Laravel Cloud

Le dépôt `SevenTechGroup/backend` peut être importé directement dans Laravel
Cloud. Aucun Dockerfile ni serveur web personnalisé n’est nécessaire.

## 1. Créer l’application

1. Créer une application depuis un dépôt existant.
2. Sélectionner `SevenTechGroup/backend` et la branche `main`.
3. Choisir une région proche des utilisateurs et placer tous les services dans
   cette même région.
4. Utiliser PHP 8.4 et activer l’extension `pdo_pgsql` si elle n’est pas déjà
   chargée.
5. Attacher une base **Serverless Postgres** avant le premier déploiement.

Laravel Cloud injecte automatiquement les identifiants de la base attachée. Il
ne faut donc pas recopier `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD` ou
`DB_DATABASE` dans les variables personnalisées.

## 2. Configurer les commandes

Commandes de build :

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan optimize
```

Commandes de déploiement :

```bash
php artisan migrate --force
php artisan db:seed --class=ProductionDataSeeder --force
```

`ProductionDataSeeder` installe seulement les territoires et catégories
nécessaires au fonctionnement de l’application. Il est idempotent et ne crée
aucun compte de démonstration.

## 3. Variables de production

Ajouter les variables suivantes dans **Environment → Settings → Environment
variables**. Les valeurs entre chevrons doivent être remplacées et ne doivent
jamais être versionnées.

```dotenv
APP_NAME="Sahel Signal"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<domaine-api>.laravel.cloud
APP_KEY=<résultat de php artisan key:generate --show>

LOG_LEVEL=warning
DB_CONNECTION=pgsql
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

JWT_SECRET=<résultat de php artisan jwt:secret --show>
CORS_ALLOWED_ORIGINS=https://<projet-frontend>.pages.dev
CORS_SUPPORTS_CREDENTIALS=false

CLOUDINARY_CLOUD_NAME=<cloud-name>
CLOUDINARY_API_KEY=<api-key>
CLOUDINARY_API_SECRET=<api-secret>
CLOUDINARY_FOLDER=sahel-signal
```

Générer `APP_KEY` et `JWT_SECRET` localement, copier seulement leurs sorties
dans Laravel Cloud, puis fermer le terminal qui les a affichées. Le frontend ne
doit recevoir aucun de ces secrets.

Le MVP n’expédie actuellement aucun job asynchrone : un cluster Worker n’est
donc pas requis au premier déploiement. Les tables de cache, session et queue
sont déjà créées par les migrations.

## 4. Premier déploiement et contrôle

Après le premier déploiement, Laravel Cloud attribue un domaine HTTPS. Mettre ce
domaine dans `APP_URL`, puis utiliser l’origine exacte du frontend dans
`CORS_ALLOWED_ORIGINS` et redéployer.

```bash
curl --fail https://<domaine-api>.laravel.cloud/up
curl --fail https://<domaine-api>.laravel.cloud/api/categories
curl --fail https://<domaine-api>.laravel.cloud/api/territories
```

Les deux dernières routes doivent retourner respectivement cinq catégories et
trois territoires. Activer ensuite les sauvegardes PostgreSQL et surveiller les
erreurs dans l’onglet **Logs** de Laravel Cloud.

