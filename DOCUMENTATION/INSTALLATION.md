# Guide d'Installation

Ce guide explique comment installer et configurer un nouveau projet à partir du skeleton PHP.

## Prérequis

- PHP 8.1 ou supérieur
- Composer installé et disponible dans le PATH
- (Optionnel) Docker et Docker Compose pour l'environnement Docker

## Installation

### Méthode 1 : Via Composer (Recommandé)

```bash
composer create-project julienlinard/php-skeleton mon-projet
cd mon-projet
```

Le script d'installation interactif sera automatiquement lancé.

### Méthode 2 : Clonage du dépôt

```bash
git clone https://github.com/julien-lin/php-skeleton.git mon-projet
cd mon-projet
composer install
php vendor/bin/php-skeleton-install
```

## Processus d'Installation Interactif

Lors de l'installation, le script vous posera plusieurs questions :

### 1. Utilisation de Docker

```
❓ Voulez-vous utiliser Docker ? (y/N)
```

- **Y** : Configure un environnement Docker avec Apache et MariaDB
- **N** : Installation locale (nécessite PHP, Apache/Nginx et MySQL/MariaDB installés localement)

### 2. Installation de Doctrine

```
❓ Voulez-vous installer Doctrine ? (y/N)
```

- **Y** : Installe le package `julienlinard/doctrine-php` pour l'ORM
- **N** : Pas d'ORM installé

### 3. Installation de Auth

```
❓ Voulez-vous installer Auth ? (y/N)
```

- **Y** : Installe le package `julienlinard/auth-php` pour l'authentification
- **N** : Pas de système d'authentification installé

### 4. Configuration de l'environnement (Docker uniquement)

Si vous avez choisi Docker, le script vous demandera de configurer :

- **Nom du conteneur Apache** : Nom personnalisé pour le conteneur Apache (par défaut : `apache_app`)
- **Nom du conteneur MariaDB** : Nom personnalisé pour le conteneur MariaDB (par défaut : `mariadb_app`)
- **Port Apache** : Port pour accéder à l'application (par défaut : `8080`)
- **Port MariaDB** : Port pour accéder à MariaDB (par défaut : `3306`)
- **Mot de passe root MariaDB** : Mot de passe pour l'utilisateur root de MariaDB
- **Nom de la base de données** : Nom de la base de données à créer
- **Utilisateur de la base de données** : Nom d'utilisateur pour la base de données
- **Mot de passe de la base de données** : Mot de passe pour l'utilisateur de la base de données

## Structure du Projet Généré

### Avec Docker

```
mon-projet/
├── .env                    # Variables d'environnement
├── .env.example           # Exemple de variables d'environnement
├── .gitignore
├── aliases.sh             # Aliases Docker (sourcez-le : source aliases.sh)
├── composer.json
├── docker-compose.yml     # Configuration Docker Compose
├── Dockerfile             # Image Docker Apache
├── php.ini                # Configuration PHP personnalisée
├── www/                   # Code source de l'application
│   ├── config/
│   ├── public/            # Point d'entrée web
│   ├── src/
│   │   ├── Controller/
│   │   ├── Entity/
│   │   ├── Middleware/
│   │   ├── Repository/
│   │   └── Service/
│   ├── views/
│   ├── migrations/
│   └── storage/
└── apache/                # Configuration Apache
```

### Sans Docker (Local)

```
mon-projet/
├── .env
├── .env.example
├── .gitignore
├── composer.json
├── config/
├── public/                # Point d'entrée web
├── src/
│   ├── Controller/
│   ├── Entity/
│   ├── Middleware/
│   ├── Repository/
│   └── Service/
├── views/
├── migrations/
└── storage/
```

## Démarrage Rapide

### Avec Docker

1. **Démarrer les conteneurs** :
   ```bash
   docker compose up -d
   ```

2. **Accéder à l'application** :
   Ouvrez votre navigateur à l'adresse : `http://localhost:8080`

3. **Aliases utiles** (après avoir sourcé `aliases.sh`) :
   ```bash
   source aliases.sh
   
   ccomposer require package/name    # Exécuter composer dans le conteneur
   capache                           # Accéder au shell du conteneur Apache
   cmariadb                          # Accéder au shell du conteneur MariaDB
   db-export                         # Exporter la base de données
   db-import                         # Importer la base de données
   ```

### Sans Docker (Local)

1. **Configurer le serveur web** :
   - Point d'entrée : `public/index.php`
   - Document root : `public/`

2. **Configurer la base de données** :
   - Modifiez `.env` avec vos paramètres de connexion

3. **Démarrer le serveur de développement** :
   ```bash
   php -S localhost:8000 -t public
   ```

4. **Accéder à l'application** :
   Ouvrez votre navigateur à l'adresse : `http://localhost:8000`

## Configuration

### Variables d'Environnement

Le fichier `.env` contient toutes les variables de configuration :

```env
APP_NAME=Mon Application
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8080
APP_LOCALE=fr
APP_SECRET=votre-secret-aleatoire

# Base de données
DB_HOST=mariadb_app
DB_PORT=3306
DB_NAME=mon_app
DB_USER=mon_user
DB_PASS=mon_password
```

### Configuration Apache (Docker)

Le fichier `apache/vhost.conf` configure le virtual host Apache.

### Configuration PHP

Le fichier `php.ini` contient les paramètres PHP personnalisés pour Docker.

## Prochaines Étapes

1. **Créer vos contrôleurs** dans `src/Controller/`
2. **Définir vos entités** dans `src/Entity/` (si Doctrine est installé)
3. **Créer vos middlewares** dans `src/Middleware/`
4. **Définir vos routes** dans vos contrôleurs avec les attributs `#[Route]`
5. **Créer vos vues** dans `views/`

## Dépannage

### Problème : Composer n'est pas trouvé

Si Composer n'est pas dans le PATH, installez les packages manuellement :

```bash
cd www  # ou à la racine si installation locale
composer require julienlinard/doctrine-php
composer require julienlinard/auth-php
```

### Problème : Les permissions ne sont pas correctes (Linux)

Le script `fix-permissions.sh` est créé automatiquement. Exécutez-le :

```bash
./fix-permissions.sh
```

### Problème : Le conteneur Docker ne démarre pas

Vérifiez les logs :

```bash
docker compose logs
```

Vérifiez que les ports ne sont pas déjà utilisés :

```bash
lsof -i :8080  # Port Apache
lsof -i :3306  # Port MariaDB
```

## Support

Pour toute question ou problème, consultez :
- [Documentation de sécurité](SECURITY.md)
- [Documentation Docker](DOCKER.md)
- [Issues GitHub](https://github.com/julien-lin/php-skeleton/issues)

