# PHP Skeleton v1.5.8

[🇬🇧 Lire en anglais](README.md) | [🇫🇷 Lire en français](README.fr.md)

## 💝 Soutenir le projet

Si ce skeleton vous est utile, envisagez de [devenir un sponsor](https://github.com/sponsors/julien-lin) pour soutenir le développement et la maintenance de ce projet open source.

---

Un skeleton de projet PHP minimal utilisant mes libraries PHP (router, core, auth, doctrine). Point de départ parfait pour construire des applications PHP modernes avec une architecture propre et une structure prête pour la production.

## ✨ Fonctionnalités

- **🚀 Prêt pour la production** - Entièrement configuré et optimisé dès l'installation
- **🏗️ Architecture propre** - Structure bien organisée avec couche de services
- **🔒 Sécurité en priorité** - Protection mass assignment, prévention open redirect, sessions sécurisées, protection CSRF
- **📦 Services auto-générés** - EnvValidator, BootstrapService, EventListenerService
- **🌐 Support multilingue** - Messages de validation en français, anglais, espagnol
- **🐳 Prêt pour Docker** - Configuration Docker complète avec Apache et MariaDB
- **⚡ Zéro configuration** - Fonctionne immédiatement après l'installation
- **⚡ Optimisé pour la performance** - Cache des vues, cache scoped du container, rate limiting

## 🚀 Installation

Créez un nouveau projet en utilisant Composer:

```bash
composer create-project julienlinard/php-skeleton my-app
```

Cela créera un nouveau répertoire `my-app` avec la structure du skeleton et lancera un installateur interactif.

## 📦 Ce qui est inclus

- **Core PHP Framework** - Structure MVC avec container d'injection de dépendances
- **Router** - Routage moderne avec attributs PHP 8
- **Validation de formulaires** - Validation avancée alimentée par php-validator avec support multilingue
- **Logging** - SimpleLogger intégré avec logging structuré
- **Gestion d'erreurs** - Gestion d'erreurs complète avec modes debug/production
- **Gestion de sessions** - Gestion sécurisée des sessions avec messages flash
- **Optionnel: Doctrine PHP** - ORM pour la gestion de base de données
- **Optionnel: Auth PHP** - Système d'authentification et d'autorisation

## 🎯 Installation Interactive

Après avoir exécuté `composer create-project`, un installateur interactif vous guidera dans la configuration :

### Étape 1 : Choisir l'environnement
- **Utiliser Docker ?** - Choisissez `y` pour la configuration Docker ou `N` pour le développement local

### Étape 2 : Configuration Docker (si Docker sélectionné)
L'installateur vous demandera de configurer :
- Nom et port du container Apache
- Nom et port du container MariaDB
- Identifiants de base de données (mot de passe root, nom de la base, utilisateur, mot de passe)
- Paramètres de rapport d'erreurs PHP

Deux fichiers `.env` seront automatiquement générés :
- `.env` à la racine - Pour la configuration Docker Compose
- `www/.env` - Pour la configuration de l'application

### Étape 3 : Packages optionnels
- **Installer Doctrine ?** - Ajoute les capacités ORM de base de données
- **Installer Auth ?** - Ajoute le système d'authentification

Répondez simplement `y` pour oui ou `N` pour non (par défaut).

**L'autoloader est automatiquement régénéré** après l'installation, votre application est donc prête à fonctionner immédiatement !

## ⚡ Démarrage rapide

### Option A : Configuration Docker

Si vous avez choisi Docker lors de l'installation :

```bash
cd my-app

# Charger les aliases (optionnel mais recommandé)
source aliases.sh

# Démarrer les containers Docker
docker compose up -d

# Installer les dépendances dans le container
cd www
ccomposer install

# Visiter votre application
# http://localhost (ou le port que vous avez configuré)
```

**Aliases Docker utiles** (après avoir sourcé `aliases.sh`) :
- `ccomposer` - Exécuter des commandes Composer dans le container
- `capache` - Entrer dans le shell du container Apache
- `cmariadb` - Entrer dans le shell du container MariaDB
- `db-export` - Exporter une sauvegarde de base de données
- `db-import` - Importer une sauvegarde de base de données

### Option B : Configuration locale

Si vous avez choisi le développement local :

```bash
cd my-app

# Installer les dépendances
composer install

# Démarrer le serveur de développement
php -S localhost:8000 -t public

# Visiter votre application
# http://localhost:8000
```

## 📁 Structure du projet

### Structure avec Docker
```
my-app/
├── apache/              # Configuration Docker Apache
│   ├── Dockerfile
│   └── custom-php.ini
├── db/                  # Scripts de base de données
│   ├── backup.sh
│   └── restore.sh
├── www/                 # Racine de l'application (Docker)
│   ├── config/          # Fichiers de configuration
│   │   └── database.php # Configuration sécurisée de la base de données
│   ├── migrations/      # Migrations de base de données
│   ├── public/          # Point d'entrée public
│   │   ├── index.php    # Fichier bootstrap
│   │   └── .htaccess    # Règles de réécriture Apache
│   ├── src/             # Code source de l'application
│   │   ├── Controller/  # Contrôleurs
│   │   ├── Entity/      # Entités Doctrine
│   │   ├── Middleware/  # Middlewares personnalisés
│   │   ├── Repository/  # Répositories de données
│   │   └── Service/     # Services de logique métier
│   │       ├── BootstrapService.php      # Configuration bootstrap
│   │       ├── EnvValidator.php          # Validation de l'environnement
│   │       └── EventListenerService.php  # Écouteurs d'événements
│   ├── storage/         # Répertoire de stockage
│   │   └── logs/        # Logs de l'application
│   ├── views/           # Templates de vues
│   │   ├── _templates/  # Templates de mise en page
│   │   └── home/        # Vues de pages
│   ├── .env             # Variables d'environnement de l'application
│   ├── .env.example     # Modèle d'environnement
│   ├── .gitignore       # Règles Git ignore
│   └── composer.json    # Dépendances du projet
├── .env                 # Variables d'environnement Docker Compose
├── .env.example         # Modèle d'environnement Docker
├── aliases.sh           # Aliases Docker
├── docker-compose.yml   # Configuration Docker Compose
└── composer.json        # composer.json racine
```

### Structure en local
```
my-app/
├── config/              # Fichiers de configuration
├── migrations/          # Migrations de base de données
├── public/              # Point d'entrée public
├── src/                 # Code source de l'application
│   └── Service/        # Services auto-générés
├── storage/             # Répertoire de stockage
│   └── logs/          # Logs de l'application
├── views/               # Templates de vues
├── .env                # Variables d'environnement
├── .env.example        # Modèle d'environnement
└── composer.json       # Dépendances du projet
```

## 🛠️ Services Auto-Générés

Le skeleton génère automatiquement trois services essentiels :

### 1. EnvValidator
Valide les variables d'environnement au démarrage de l'application :
- Validation de `APP_SECRET` (minimum 32 caractères)
- Validation de `APP_LOCALE` (supporté : fr, en, es)

### 2. BootstrapService
Centralise la configuration du bootstrap :
- Configuration du mode debug
- Configuration du gestionnaire d'erreurs avec logging
- Configuration de la sécurité des sessions

### 3. EventListenerService
Enregistre les écouteurs d'événements de l'application :
- Logging des requêtes
- Logging des réponses
- Logging des exceptions

## 📚 Packages disponibles

Ce skeleton utilise les packages suivants:

- **[julienlinard/php-router](https://packagist.org/packages/julienlinard/php-router)** - Routeur moderne avec attributs PHP 8
- **[julienlinard/core-php](https://packagist.org/packages/julienlinard/core-php)** - Framework MVC avec container DI
- **[julienlinard/php-validator](https://packagist.org/packages/julienlinard/php-validator)** - Validation avancée avec support multilingue
- **[julienlinard/doctrine-php](https://packagist.org/packages/julienlinard/doctrine-php)** - ORM (optionnel)
- **[julienlinard/auth-php](https://packagist.org/packages/julienlinard/auth-php)** - Authentification (optionnel)

## 🛠️ Développement

### Ajouter des contrôleurs

Créez des contrôleurs dans le répertoire `src/Controller/`. Les contrôleurs doivent étendre la classe de base `Controller`:

```php
<?php

namespace App\Controller;

use JulienLinard\Core\Controller\Controller;
use JulienLinard\Router\Attributes\Route;
use JulienLinard\Router\Response;

class MonController extends Controller
{
    #[Route(path: '/a-propos', methods: ['GET'], name: 'about')]
    public function aPropos(): Response
    {
        return $this->json(['page' => 'À propos']);
    }
    
    #[Route(path: '/contact', methods: ['GET'], name: 'contact')]
    public function contact(): Response
    {
        return $this->view('contact/index', [
            'title' => 'Contactez-nous'
        ]);
    }
}
```

Enregistrez les routes dans `public/index.php` (ou `www/public/index.php` pour Docker):

```php
$router->registerRoutes(MonController::class);
```

### Validation de formulaires

Le skeleton inclut `php-validator` avec support multilingue:

```php
<?php

namespace App\Controller;

use JulienLinard\Core\Controller\Controller;
use JulienLinard\Core\Form\Validator as CoreValidator;
use JulienLinard\Router\Attributes\Route;
use JulienLinard\Router\Request;
use JulienLinard\Router\Response;

class ContactController extends Controller
{
    public function __construct(
        private CoreValidator $validator
    ) {}
    
    #[Route(path: '/contact', methods: ['POST'], name: 'contact.submit')]
    public function submit(Request $request): Response
    {
        $result = $this->validator->validate($request->getData(), [
            'name' => 'required|min:3|max:100',
            'email' => 'required|email',
            'message' => 'required|min:10|max:1000'
        ]);
        
        if ($result->hasErrors()) {
            return $this->json([
                'success' => false,
                'errors' => $result->getErrors()
            ], 422);
        }
        
        // Traiter les données valides
        return $this->json(['success' => true, 'message' => 'Formulaire de contact soumis']);
    }
}
```

### Utiliser l'injection de dépendances

Les services sont automatiquement enregistrés dans le container DI. Accédez-y dans les contrôleurs:

```php
<?php

namespace App\Controller;

use JulienLinard\Core\Controller\Controller;
use JulienLinard\Doctrine\EntityManager;
use JulienLinard\Auth\AuthManager;

class MonController extends Controller
{
    public function __construct(
        private EntityManager $em,
        private AuthManager $auth
    ) {}
}
```

### Messages Flash

Les messages flash sont automatiquement affichés dans le template d'en-tête:

```php
use JulienLinard\Core\Session\Session;

// Définir un message flash
Session::setFlash('success', 'Opération réussie !');
Session::setFlash('error', 'Une erreur est survenue !');
```

### Logging

Utilisez SimpleLogger pour le logging structuré:

```php
use JulienLinard\Core\Logging\SimpleLogger;

$logger = new SimpleLogger('/path/to/logs/app.log');
$logger->info('Utilisateur connecté', ['user_id' => 123]);
$logger->error('Échec de connexion à la base de données', ['error' => $e->getMessage()]);
```

### Configuration

Les variables d'environnement sont automatiquement chargées depuis `.env`:

```env
# Application
APP_DEBUG=true
APP_LOCALE=fr
APP_SECRET=votre-cle-secrete-ici-min-32-caracteres

# Base de données (pour Docker, utilisez le nom du service comme host)
MARIADB_CONTAINER=mariadb_app
MARIADB_PORT=3306
MYSQL_DATABASE=app_db
MYSQL_USER=app_user
MYSQL_PASSWORD=app_password
```

## 🔒 Fonctionnalités de sécurité

- **Protection CSRF** - Génération et validation automatiques des tokens CSRF
- **Sécurité des sessions** - Cookies HttpOnly, SameSite et sécurisés
- **Validation de l'environnement** - Validation automatique des variables d'environnement requises
- **Configuration sécurisée de la base de données** - Aucun identifiant en dur, validation stricte
- **Gestion d'erreurs** - Gestion d'erreurs sécurisée pour la production avec logging

## 📝 Prérequis

- PHP 8.1 ou supérieur
- Composer
- Docker (optionnel, pour la configuration Docker)

## 🆕 Nouveautés dans v1.5.3

- ✅ Régénération automatique de l'autoloader après l'installation
- ✅ Architecture propre avec couche de services
- ✅ Services auto-générés (EnvValidator, BootstrapService, EventListenerService)
- ✅ Support multilingue de validation (fr, en, es)
- ✅ Configuration sécurisée dès l'installation
- ✅ Messages flash avec auto-hide
- ✅ Structure prête pour la production

## 📄 License

MIT License - Voir le fichier LICENSE pour plus de détails.

## 🤝 Contribution

Les contributions sont les bienvenues ! N'hésitez pas à ouvrir une issue ou une pull request.

## 📧 Support

Pour toute question ou problème, veuillez ouvrir une issue sur GitHub.

## 💝 Soutenir le projet

Si ce skeleton vous est utile, envisagez de [devenir un sponsor](https://github.com/sponsors/julien-lin) pour soutenir le développement et la maintenance de ce projet open source.

---

**Développé avec ❤️ par Julien Linard**
