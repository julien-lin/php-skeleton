# PHP Skeleton

[🇫🇷 Read in French](README.fr.md) | [🇬🇧 Read in English](README.md)

## 💝 Support the project

If this skeleton is useful to you, consider [becoming a sponsor](https://github.com/sponsors/julien-lin) to support the development and maintenance of this open source project.

---

A minimal PHP project skeleton using my PHP libraries (router, core, auth, doctrine). Perfect starting point for building modern PHP applications.

## 🚀 Installation

Create a new project using Composer:

```bash
composer create-project julienlinard/php-skeleton my-app
```

This will create a new directory `my-app` with the skeleton structure.

## 📦 What's Included

- **Core PHP Framework** - MVC structure with dependency injection
- **Router** - Modern routing with PHP 8 attributes
- **Form Validation** - Advanced validation powered by php-validator (included in core-php)
- **Optional: Doctrine PHP** - ORM for database management
- **Optional: Auth PHP** - Authentication and authorization system

## 🎯 Interactive Installation

After running `composer create-project`, an interactive installer will guide you through the setup:

### Step 1: Choose Environment
- **Use Docker?** - Choose `y` for Docker setup or `N` for local development

### Step 2: Docker Configuration (if Docker selected)
The installer will ask you to configure:
- Apache container name and port
- MariaDB container name and port
- Database credentials (root password, database name, user, password)
- PHP error reporting settings

A `.env` file will be automatically generated with your answers.

### Step 3: Optional Packages
- **Install Doctrine?** - Adds database ORM capabilities
- **Install Auth?** - Adds authentication system

Simply answer `y` for yes or `N` for no (default).

## ⚡ Quick Start

### Option A: Docker Setup

If you chose Docker during installation:

```bash
cd my-app

# Load aliases (optional but recommended)
source aliases.sh

# Start Docker containers
docker compose up -d

# Install dependencies in container
ccomposer install

# Visit your application
# http://localhost (or the port you configured)
```

**Useful Docker aliases** (after sourcing `aliases.sh`):
- `ccomposer` - Run Composer commands in container
- `capache` - Enter Apache container shell
- `cmariadb` - Enter MariaDB container shell
- `db-export` - Export database backup
- `db-import` - Import database backup

### Option B: Local Setup

If you chose local development:

```bash
cd my-app

# Install dependencies
composer install

# Start the development server
php -S localhost:8000 -t public

# Visit your application
# http://localhost:8000
```

You should see a JSON response:

```json
{
    "message": "Hello World!",
    "status": "success",
    "framework": "PHP Skeleton by Julien Linard"
}
```

## 📁 Project Structure

```
my-app/
├── apache/          # Docker Apache configuration (if Docker chosen)
│   ├── Dockerfile
│   └── custom-php.ini
├── config/          # Configuration files
├── db/              # Database scripts (if Docker chosen)
│   ├── backup.sh
│   └── restore.sh
├── public/          # Public entry point (web root)
│   ├── index.php    # Bootstrap file
│   └── .htaccess    # Apache rewrite rules (if Docker)
├── src/             # Application source code
├── templates/       # View templates
├── vendor/          # Composer dependencies
├── .env             # Environment variables (generated, not in git)
├── .env.example     # Environment template
├── aliases.sh       # Docker aliases (if Docker chosen)
├── docker-compose.yml # Docker Compose config (if Docker chosen)
└── composer.json    # Project dependencies
```

## 📚 Available Packages

This skeleton uses the following packages:

- **[julienlinard/php-router](https://packagist.org/packages/julienlinard/php-router)** - Modern router with PHP 8 attributes
- **[julienlinard/core-php](https://packagist.org/packages/julienlinard/core-php)** - MVC framework with DI container, includes form validation
- **[julienlinard/doctrine-php](https://packagist.org/packages/julienlinard/doctrine-php)** - ORM (optional)
- **[julienlinard/auth-php](https://packagist.org/packages/julienlinard/auth-php)** - Authentication (optional)

## 🛠️ Development

### Adding Controllers

Create controllers in the `src/` directory. Controllers should extend the base `Controller` class:

```php
<?php

namespace Julien;

use JulienLinard\Core\Controller\Controller;
use JulienLinard\Router\Attributes\Route;
use JulienLinard\Router\Response;

class MyController extends Controller
{
    #[Route(path: '/about', methods: ['GET'], name: 'about')]
    public function about(): Response
    {
        return $this->json(['page' => 'About']);
    }
    
    #[Route(path: '/contact', methods: ['GET'], name: 'contact')]
    public function contact(): Response
    {
        return $this->view('contact/index', [
            'title' => 'Contact Us'
        ]);
    }
}
```

Register routes in `public/index.php`:

```php
$router->registerRoutes(MyController::class);
```

### Form Validation

The skeleton includes `core-php` which provides form validation powered by `php-validator`:

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
        
        // Process valid data
        return $this->json(['success' => true, 'message' => 'Contact form submitted']);
    }
}
```

### Configuration

Create a `.env` file in the project root for environment variables:

```env
APP_DEBUG=true
APP_ENV=development
```

## 📝 Requirements

- PHP 8.1 or higher
- Composer

## 📄 License

MIT License - See the LICENSE file for more details.

## 🤝 Contributing

Contributions are welcome! Feel free to open an issue or a pull request.

## 📧 Support

For any questions or issues, please open an issue on GitHub.

## 💝 Support the project

If this skeleton is useful to you, consider [becoming a sponsor](https://github.com/sponsors/julien-lin) to support the development and maintenance of this open source project.

---

**Developed with ❤️ by Julien Linard**

