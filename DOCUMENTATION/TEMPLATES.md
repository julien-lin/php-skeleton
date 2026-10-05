# Variables des templates générés

Cette référence décrit les variables consommées par les fichiers générés par `php-skeleton`. Les valeurs sensibles doivent rester dans `.env` et ne doivent jamais être copiées dans les templates ou commitées.

## Variables d’environnement locales

| Variable | Profils | Rôle | Exemple par défaut |
|---|---|---|---|
| `APP_NAME` | Tous | Nom affiché par l’application ou utilisé par les services | `My PHP Application` |
| `APP_ENV` | Tous | Environnement courant | `local` |
| `APP_DEBUG` | Tous | Active le mode debug (`1`/`0`) | `1` |
| `APP_LOCALE` | Tous | Locale applicative | `fr` |
| `APP_SECRET` | Tous | Secret des sessions et tokens | généré aléatoirement |
| `DB_HOST` | Doctrine/Auth/API | Hôte de la base de données | `127.0.0.1` en local |
| `DB_PORT` | Doctrine/Auth/API | Port de la base de données | `3306` |
| `DB_NAME` | Doctrine/Auth/API | Nom de la base | `app_db` |
| `DB_USER` | Doctrine/Auth/API | Utilisateur de la base | `app_user` |
| `DB_PASS` | Doctrine/Auth/API | Mot de passe de la base | généré aléatoirement |
| `API_CORS_ORIGINS` | API | Origines CORS séparées par des virgules | vide |

Le profil de base ne génère pas les variables `DB_*`. `APP_SECRET` reste obligatoire avant l’activation de l’authentification.

## Variables Docker

Le `.env` à la racine configure Docker Compose :

| Variable | Rôle |
|---|---|
| `APACHE_CONTAINER` / `APACHE_PORT` | Nom du service Apache et port exposé sur l’hôte |
| `MARIADB_CONTAINER` / `MARIADB_PORT` | Nom du service MariaDB et port exposé sur l’hôte, uniquement avec Doctrine |
| `MYSQL_ROOT_PASSWORD` | Mot de passe root MariaDB |
| `MYSQL_DATABASE` / `MYSQL_USER` / `MYSQL_PASSWORD` | Identifiants de la base MariaDB |
| `PHP_ERROR_REPORTING` / `PHP_DISPLAY_ERRORS` | Configuration PHP injectée dans le conteneur |

Dans `www/.env`, `DB_HOST` devient le nom du service MariaDB et `DB_PORT` reste le port interne `3306`. `MARIADB_PORT` ne doit donc pas être réutilisé par l’application.

Les expressions `${...}` présentes dans `docker-compose.yml` sont des variables Docker Compose, pas des placeholders à remplacer dans les fichiers PHP.

## Variables des vues

Les vues PHP générées utilisent le contexte transmis par `View::render()` :

| Variable | Fichiers | Utilisation |
|---|---|---|
| `$title` | `views/home/index.html.php`, `views/_templates/_header.html.php` | Titre de la page ; valeur de repli si absente |
| `$message` | `views/home/index.html.php` | Message d’accueil ; valeur de repli si absent |
| `$headerSuccess` / `$headerError` | `views/_templates/_header.html.php` | Messages flash lus depuis la session |

Les valeurs affichées dans les vues PHP sont échappées avec `htmlspecialchars()`.

Avec le profil Vision, la vue d’accueil utilise les équivalents Vision `{{ title }}` et `{{ message }}`. Ces marqueurs sont intentionnels et ne doivent pas être remplacés par l’installateur.

## Variables générées par le bootstrap

Le bootstrap lit notamment `APP_ENV`, `APP_DEBUG`, `APP_SECRET`, `APP_LOCALE` et `API_CORS_ORIGINS` via `getenv()`. Les services Doctrine/Auth sont enregistrés uniquement lorsque le profil correspondant est activé. Les middlewares API sont enregistrés dans leur groupe de routes et ne reçoivent pas le CSRF web.

Lorsqu’une nouvelle variable est ajoutée à un template, elle doit être ajoutée à ce document et à `.env.example` du profil concerné.
