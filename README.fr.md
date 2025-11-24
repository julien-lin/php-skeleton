# PHP Skeleton

[🇬🇧 Lire en anglais](README.md) | [🇫🇷 Lire en français](README.fr.md)

## 💝 Soutenir le projet

Si ce skeleton vous est utile, envisagez de [devenir un sponsor](https://github.com/sponsors/julien-lin) pour soutenir le développement et la maintenance de ce projet open source.

---

Un skeleton de projet PHP minimal utilisant mes libraries PHP (router, core, auth, doctrine). Point de départ parfait pour construire des applications PHP modernes.

## 🚀 Installation

Créez un nouveau projet en utilisant Composer:

```bash
composer create-project julienlinard/php-skeleton my-app
```

Cela créera un nouveau répertoire `my-app` avec la structure du skeleton.

## 📦 Ce qui est inclus

- **Core PHP Framework** - Structure MVC avec injection de dépendances
- **Router** - Routage moderne avec attributs PHP 8
- **Validation de formulaires** - Validation avancée alimentée par php-validator (inclus dans core-php)
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

Un fichier `.env` sera automatiquement généré avec vos réponses.

### Étape 3 : Packages optionnels
- **Installer Doctrine ?** - Ajoute les capacités ORM de base de données
- **Installer Auth ?** - Ajoute le système d'authentification

Répondez simplement `y` pour oui ou `N` pour non (par défaut).

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

Vous devriez voir une réponse JSON:

```json
{
    "message": "Hello World!",
    "status": "success",
    "framework": "PHP Skeleton by Julien Linard"
}
```

## 📁 Structure du projet

```
my-app/
├── apache/          # Configuration Docker Apache (si Docker choisi)
│   ├── Dockerfile
│   └── custom-php.ini
├── config/          # Fichiers de configuration
├── db/              # Scripts de base de données (si Docker choisi)
│   ├── backup.sh
│   └── restore.sh
├── public/          # Point d'entrée public (racine web)
│   ├── index.php    # Fichier bootstrap
│   └── .htaccess    # Règles de réécriture Apache (si Docker)
├── src/             # Code source de l'application
├── templates/       # Templates de vues
├── vendor/          # Dépendances Composer
├── .env             # Variables d'environnement (généré, pas dans git)
├── .env.example     # Modèle d'environnement
├── aliases.sh       # Aliases Docker (si Docker choisi)
├── docker-compose.yml # Configuration Docker Compose (si Docker choisi)
└── composer.json    # Dépendances du projet
```

## 📚 Packages disponibles

Ce skeleton utilise les packages suivants:

- **[julienlinard/php-router](https://packagist.org/packages/julienlinard/php-router)** - Routeur moderne avec attributs PHP 8
- **[julienlinard/core-php](https://packagist.org/packages/julienlinard/core-php)** - Framework MVC avec container DI, inclut la validation de formulaires
- **[julienlinard/doctrine-php](https://packagist.org/packages/julienlinard/doctrine-php)** - ORM (optionnel)
- **[julienlinard/auth-php](https://packagist.org/packages/julienlinard/auth-php)** - Authentification (optionnel)

## 🛠️ Développement

### Ajouter des contrôleurs

Créez des contrôleurs dans le répertoire `src/`. Les contrôleurs doivent étendre la classe de base `Controller`:

```php
<?php

namespace Julien;

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

Enregistrez les routes dans `public/index.php`:

```php
$router->registerRoutes(MonController::class);
```

### Validation de formulaires

Le skeleton inclut `core-php` qui fournit une validation de formulaires alimentée par `php-validator`:

```php
<?php

namespace Julien;

use JulienLinard\Core\Controller\Controller;
use JulienLinard\Core\Form\Validator;
use JulienLinard\Router\Attributes\Route;
use JulienLinard\Router\Request;
use JulienLinard\Router\Response;

class ContactController extends Controller
{
    #[Route(path: '/contact', methods: ['POST'], name: 'contact.submit')]
    public function submit(Request $request): Response
    {
        $validator = new Validator();
        $result = $validator->validate($request->getData(), [
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

### Configuration

Créez un fichier `.env` à la racine du projet pour les variables d'environnement:

```env
APP_DEBUG=true
APP_ENV=development
```

## 📝 Prérequis

- PHP 8.1 ou supérieur
- Composer

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

