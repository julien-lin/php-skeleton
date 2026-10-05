# PHP Skeleton v1.6.0

[🇫🇷 Read in French](README.fr.md) | [🇬🇧 Read in English](README.md)

## 💝 Support the project

If this skeleton is useful to you, consider [becoming a sponsor](https://github.com/sponsors/julien-lin) to support the development and maintenance of this open source project.

---

A minimal PHP project skeleton using my PHP libraries (router, core, auth, doctrine). Perfect starting point for building modern PHP applications with clean architecture and production-ready structure.

## ✨ Features

- **🚀 Production-Ready** - Fully configured and optimized out of the box
- **🏗️ Clean Architecture** - Well-organized structure with service layer
- **🔒 Security First** - Mass assignment protection, open redirect prevention, secure sessions, CSRF protection
- **📦 Auto-Generated Services** - EnvValidator, BootstrapService, EventListenerService
- **🌐 Multilingual Support** - Validation messages in French, English, Spanish
- **🐳 Docker Ready** - Complete Docker setup with Apache and MariaDB
- **⚡ Zero Configuration** - Works immediately after installation
- **⚡ Performance Optimized** - View caching, container scoped cache, rate limiting

## 🚀 Installation

Create a new project using Composer:

```bash
composer create-project julienlinard/php-skeleton my-app
```

This will create a new directory `my-app` with the skeleton structure and launch an interactive installer.

## 📦 What's Included

- **Core PHP Framework** - MVC structure with dependency injection container
- **Router** - Modern routing with PHP 8 attributes
- **Form Validation** - Advanced validation powered by php-validator with multilingual support
- **Logging** - Integrated SimpleLogger with structured logging
- **Error Handling** - Comprehensive error handling with debug/production modes
- **Session Management** - Secure session handling with flash messages
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

Two `.env` files will be automatically generated:
- Root `.env` - For Docker Compose configuration
- `www/.env` - For application configuration

### Step 3: Optional Packages
- **Install Doctrine?** - Adds database ORM capabilities
- **Install Auth?** - Adds authentication system
- **Install API?** - Adds `php-api`, Doctrine, a `Product` entity and CRUD routes under `/api/products`
- **Install Vision?** - Adds `php-vision` and generates `.html.vis` templates instead of PHP templates
- **Enable secure profile?** - Registers request validation, rate limiting, security headers and gzip compression

Simply answer `y` for yes or `N` for no (default).

The API profile automatically enables the database profile. The Vision profile is independent and is never installed in the base profile.
The secure profile uses only the existing Core/Router middleware stack. It stores rate-limit counters under `storage/cache/rate-limit`, enables HSTS only in production, and keeps CSRF enabled for web forms while API routes remain stateless.
The API profile exposes no cross-origin access by default. Set `API_CORS_ORIGINS` in `.env` to a comma-separated allowlist when browser clients need CORS; API routes are excluded from CSRF checks.

**The autoloader is automatically regenerated** after installation, so your application is ready to run immediately!

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
cd www
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

## 📁 Project Structure

### Docker Setup Structure
```
my-app/
├── apache/              # Docker Apache configuration
│   ├── Dockerfile
│   └── custom-php.ini
├── db/                  # Database scripts
│   ├── backup.sh
│   └── restore.sh
├── www/                 # Application root (Docker)
│   ├── config/          # Configuration files
│   │   └── database.php # Secure database config
│   ├── migrations/      # Database migrations
│   ├── public/          # Public entry point
│   │   ├── index.php    # Bootstrap file
│   │   └── .htaccess    # Apache rewrite rules
│   ├── src/             # Application source code
│   │   ├── Controller/   # Controllers
│   │   ├── Entity/      # Doctrine entities
│   │   ├── Middleware/  # Custom middlewares
│   │   ├── Repository/  # Data repositories
│   │   └── Service/     # Business logic services
│   │       ├── BootstrapService.php      # Bootstrap configuration
│   │       ├── EnvValidator.php          # Environment validation
│   │       └── EventListenerService.php  # Event listeners
│   ├── storage/         # Storage directory
│   │   └── logs/        # Application logs
│   ├── views/           # View templates
│   │   ├── _templates/  # Layout templates
│   │   └── home/        # Page views
│   ├── .env             # Application environment variables
│   ├── .env.example     # Environment template
│   ├── .gitignore       # Git ignore rules
│   └── composer.json    # Project dependencies
├── .env                 # Docker Compose environment variables
├── .env.example         # Docker environment template
├── aliases.sh           # Docker aliases
├── docker-compose.yml   # Docker Compose config
└── composer.json        # Root composer.json
```

### Local Setup Structure
```
my-app/
├── config/              # Configuration files
├── migrations/          # Database migrations
├── public/              # Public entry point
├── src/                # Application source code
│   └── Service/        # Auto-generated services
├── storage/            # Storage directory
│   └── logs/          # Application logs
├── views/              # View templates
├── .env               # Environment variables
├── .env.example       # Environment template
└── composer.json      # Project dependencies
```

## 🛠️ Auto-Generated Services

The skeleton automatically generates three essential services:

### 1. EnvValidator
Validates environment variables on application startup:
- `APP_SECRET` validation (minimum 32 characters)
- `APP_LOCALE` validation (supported: fr, en, es)

### 2. BootstrapService
Centralizes bootstrap configuration:
- Debug mode configuration
- Error handler setup with logging
- Session security configuration

### 3. EventListenerService
Registers application event listeners:
- Request logging
- Response logging
- Exception logging

## 📚 Available Packages

This skeleton uses the following packages:

- **[julienlinard/php-router](https://packagist.org/packages/julienlinard/php-router)** - Modern router with PHP 8 attributes
- **[julienlinard/core-php](https://packagist.org/packages/julienlinard/core-php)** - MVC framework with DI container
- **[julienlinard/php-validator](https://packagist.org/packages/julienlinard/php-validator)** - Advanced validation with multilingual support
- **[julienlinard/doctrine-php](https://packagist.org/packages/julienlinard/doctrine-php)** - ORM (optional)
- **[julienlinard/auth-php](https://packagist.org/packages/julienlinard/auth-php)** - Authentication (optional)

## 🛠️ Development

### Adding Controllers

Create controllers in the `src/Controller/` directory. Controllers should extend the base `Controller` class:

```php
<?php

namespace App\Controller;

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

Register routes in `public/index.php` (or `www/public/index.php` for Docker):

```php
$router->registerRoutes(MyController::class);
```

### Form Validation

The skeleton includes `php-validator` with multilingual support:

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
        
        // Process valid data
        return $this->json(['success' => true, 'message' => 'Contact form submitted']);
    }
}
```

### Using Dependency Injection

Services are automatically registered in the DI container. Access them in controllers:

```php
<?php

namespace App\Controller;

use JulienLinard\Core\Controller\Controller;
use JulienLinard\Doctrine\EntityManager;
use JulienLinard\Auth\AuthManager;

class MyController extends Controller
{
    public function __construct(
        private EntityManager $em,
        private AuthManager $auth
    ) {}
}
```

### Flash Messages

Flash messages are automatically displayed in the header template:

```php
use JulienLinard\Core\Session\Session;

// Set flash message
Session::setFlash('success', 'Operation completed successfully!');
Session::setFlash('error', 'An error occurred!');
```

### Logging

Use SimpleLogger for structured logging:

```php
use JulienLinard\Core\Logging\SimpleLogger;

$logger = new SimpleLogger('/path/to/logs/app.log');
$logger->info('User logged in', ['user_id' => 123]);
$logger->error('Database connection failed', ['error' => $e->getMessage()]);
```

### Configuration

Environment variables are automatically loaded from `.env`:

```env
# Application
APP_DEBUG=true
APP_LOCALE=fr
APP_SECRET=your-secret-key-here-min-32-chars

# Database (for Docker, use service name as host)
MARIADB_CONTAINER=mariadb_app
MARIADB_PORT=3306
MYSQL_DATABASE=app_db
MYSQL_USER=app_user
MYSQL_PASSWORD=app_password
```

## 🔒 Security Features

- **Mass Assignment Protection** - Models protected with `$fillable`/`$guarded` properties
- **Open Redirect Prevention** - URL validation to prevent redirect attacks
- **CSRF Protection** - Automatic CSRF token generation and validation
- **Session Security** - HttpOnly, Secure, SameSite cookies with automatic ID regeneration (every 15 min)
- **Rate Limiting** - Built-in middleware to prevent brute force attacks
- **Security Headers** - CSP, HSTS, X-Frame-Options, and more
- **Sensitive Data Redaction** - Automatic masking of passwords/tokens in logs
- **Environment Validation** - Automatic validation of required environment variables
- **Secure Database Config** - No hardcoded credentials, strict validation
- **Error Handling** - Production-safe error handling with logging

## ⚡ Performance Features

- **View Caching** - File-based cache for rendered templates with automatic invalidation
- **Container Scoped Cache** - 50-70% faster dependency resolution
- **Rate Limit Memory Cache** - 5-10x faster than file-only storage
- **Log Rotation** - Automatic compression and cleanup to save disk space
- **Response Compression** - Gzip middleware for bandwidth optimization

## 📝 Requirements

- PHP 8.1 or higher
- Composer
- Docker (optional, for Docker setup)

## 🆕 What's New in v1.6.0

- ✅ Secure profile with request validation, rate limiting, security headers and gzip compression
- ✅ API, Doctrine, Auth and Vision profiles covered by Composer and runtime smoke tests
- ✅ Templates and Composer execution extracted into injectable services
- ✅ Transactional staging, input validation, PHP linting and lockfile consistency checks
- ✅ Docker hardening, secret redaction and non-interactive CI support

### Previous Updates (v1.5.13)

- ✅ Automatic autoloader regeneration after installation
- ✅ Clean architecture with service layer
- ✅ Auto-generated services (EnvValidator, BootstrapService, EventListenerService)
- ✅ Multilingual validation support (fr, en, es)
- ✅ Secure configuration out of the box
- ✅ Flash messages with auto-hide
- ✅ Production-ready structure

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
