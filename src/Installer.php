<?php

declare(strict_types=1);

namespace Julien;

class Installer
{
    public static function postInstall(): void
    {
        self::displayWelcome();
        
        $useDocker = self::askQuestion('Voulez-vous utiliser Docker ? (y/N)', false);
        
        if ($useDocker) {
            self::setupDocker();
            self::configureEnv();
        } else {
            self::setupLocal();
        }
        
        $installDoctrine = self::askQuestion('Voulez-vous installer Doctrine ? (y/N)', false);
        $installAuth = self::askQuestion('Voulez-vous installer Auth ? (y/N)', false);
        
        $baseDir = self::getProjectRoot();
        $wwwDir = $baseDir . '/www';
        
        if ($installDoctrine) {
            if ($useDocker) {
                self::installPackageInDocker('julienlinard/doctrine-php', $wwwDir);
            } else {
                self::installPackage('julienlinard/doctrine-php', $baseDir);
            }
        }
        
        if ($installAuth) {
            if ($useDocker) {
                self::installPackageInDocker('julienlinard/auth-php', $wwwDir);
            } else {
                self::installPackage('julienlinard/auth-php', $baseDir);
            }
        }
        
        self::copyComposerJson($baseDir, $wwwDir, $installDoctrine, $installAuth);
        self::createPublicIndex($wwwDir . '/public', $installDoctrine, $installAuth);
        
        self::displayCompletion($useDocker);
    }
    
    private static function displayWelcome(): void
    {
        echo "\n";
        echo "╔═══════════════════════════════════════════════════════════╗\n";
        echo "║         PHP Skeleton - Installation Interactive          ║\n";
        echo "╚═══════════════════════════════════════════════════════════╝\n";
        echo "\n";
    }
    
    private static function askQuestion(string $question, bool $default = false): bool
    {
        $defaultText = $default ? 'Y' : 'N';
        echo "❓ {$question} [{$defaultText}]: ";
        
        $handle = fopen('php://stdin', 'r');
        if (!$handle) {
            return $default;
        }
        
        $answer = trim((string) fgets($handle));
        fclose($handle);
        
        if (empty($answer)) {
            return $default;
        }
        
        return strtolower($answer) === 'y' || strtolower($answer) === 'yes';
    }
    
    private static function installPackage(string $package, string $baseDir): void
    {
        echo "\n📦 Installation de {$package}...\n";
        
        $composerPath = self::findComposer();
        if (!$composerPath) {
            echo "❌ Erreur: Composer n'est pas disponible dans le PATH.\n";
            echo "   Veuillez installer {$package} manuellement:\n";
            echo "   composer require {$package}\n";
            return;
        }
        
        $command = 'cd ' . escapeshellarg($baseDir) . ' && ' . escapeshellarg($composerPath) . ' require ' . escapeshellarg($package) . ' --no-interaction';
        $output = [];
        $returnCode = 0;
        
        exec($command . ' 2>&1', $output, $returnCode);
        
        if ($returnCode === 0) {
            echo "✅ {$package} installé avec succès.\n";
        } else {
            echo "❌ Erreur lors de l'installation de {$package}.\n";
            echo "   Sortie: " . implode("\n   ", $output) . "\n";
            echo "   Veuillez installer manuellement: composer require {$package}\n";
        }
    }
    
    private static function installPackageInDocker(string $package, string $wwwDir): void
    {
        echo "\n📦 Installation de {$package} dans www/...\n";
        
        if (!is_dir($wwwDir)) {
            echo "❌ Erreur: Le répertoire www/ n'existe pas.\n";
            return;
        }
        
        $composerPath = self::findComposer();
        if (!$composerPath) {
            echo "⚠️  Composer n'est pas disponible. Installation à faire manuellement:\n";
            echo "   cd www && composer require {$package}\n";
            echo "   Ou après démarrage Docker: ccomposer require {$package}\n";
            return;
        }
        
        $command = 'cd ' . escapeshellarg($wwwDir) . ' && ' . escapeshellarg($composerPath) . ' require ' . escapeshellarg($package) . ' --no-interaction';
        $output = [];
        $returnCode = 0;
        
        exec($command . ' 2>&1', $output, $returnCode);
        
        if ($returnCode === 0) {
            echo "✅ {$package} installé avec succès dans www/.\n";
        } else {
            echo "⚠️  Installation échouée. À faire manuellement:\n";
            echo "   cd www && composer require {$package}\n";
            echo "   Ou après démarrage Docker: ccomposer require {$package}\n";
        }
    }
    
    private static function findComposer(): ?string
    {
        $possiblePaths = [
            'composer',
            'composer.phar',
            __DIR__ . '/../../composer.phar',
        ];
        
        foreach ($possiblePaths as $path) {
            if (self::isExecutable($path)) {
                return $path;
            }
        }
        
        $whichComposer = trim((string) shell_exec('which composer 2>/dev/null'));
        if (!empty($whichComposer) && self::isExecutable($whichComposer)) {
            return $whichComposer;
        }
        
        return null;
    }
    
    private static function isExecutable(string $path): bool
    {
        if ($path === 'composer' || $path === 'composer.phar') {
            $which = trim((string) shell_exec('which ' . escapeshellarg($path) . ' 2>/dev/null'));
            if (!empty($which)) {
                return is_executable($which);
            }
            return false;
        }
        
        return file_exists($path) && is_executable($path);
    }
    
    private static function setupDocker(): void
    {
        echo "\n🐳 Configuration Docker...\n";
        
        $baseDir = self::getProjectRoot();
        
        self::createWwwStructure($baseDir);
        self::createDockerFiles($baseDir);
        
        echo "✅ Fichiers Docker créés.\n";
    }
    
    private static function getProjectRoot(): string
    {
        return getcwd() ?: dirname(__DIR__, 1);
    }
    
    private static function createWwwStructure(string $baseDir): void
    {
        $wwwDir = $baseDir . '/www';
        $publicDir = $wwwDir . '/public';
        $viewsDir = $wwwDir . '/views';
        $templatesDir = $viewsDir . '/_templates';
        $homeDir = $viewsDir . '/home';
        
        if (!is_dir($wwwDir)) {
            mkdir($wwwDir, 0755, true);
        }
        if (!is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }
        if (!is_dir($viewsDir)) {
            mkdir($viewsDir, 0755, true);
        }
        if (!is_dir($templatesDir)) {
            mkdir($templatesDir, 0755, true);
        }
        if (!is_dir($homeDir)) {
            mkdir($homeDir, 0755, true);
        }
        
        self::moveExistingFiles($baseDir, $wwwDir);
        self::createHtaccess($publicDir);
        self::createHeaderTemplate($templatesDir);
        self::createFooterTemplate($templatesDir);
        self::createHomeView($homeDir);
        self::createWwwDirectories($wwwDir);
        self::createConfigDatabase($wwwDir);
        
        echo "✅ Structure www/ créée.\n";
    }
    
    private static function moveExistingFiles(string $baseDir, string $wwwDir): void
    {
        $filesToMove = ['public', 'src', 'templates', 'config', 'vendor'];
        
        foreach ($filesToMove as $item) {
            $source = $baseDir . '/' . $item;
            $target = $wwwDir . '/' . $item;
            
            if (is_dir($source) && !is_dir($target)) {
                self::moveDirectory($source, $target);
            } elseif (is_file($source) && !is_file($target)) {
                rename($source, $target);
            }
        }
        
        self::cleanupRootFiles($baseDir);
    }
    
    private static function cleanupRootFiles(string $baseDir): void
    {
        $filesToRemove = ['public', 'src', 'templates', 'config', 'vendor', 'composer.lock'];
        
        foreach ($filesToRemove as $item) {
            $path = $baseDir . '/' . $item;
            if (is_dir($path)) {
                self::removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
        
        self::removeInstallerFromWww($baseDir);
    }
    
    private static function removeInstallerFromWww(string $baseDir): void
    {
        $wwwDir = $baseDir . '/www';
        $installerPath = $wwwDir . '/src/Installer.php';
        
        if (file_exists($installerPath)) {
            unlink($installerPath);
        }
    }
    
    private static function moveDirectory(string $source, string $target): void
    {
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $targetPath = $target . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                if (!is_file($targetPath)) {
                    rename($item->getPathname(), $targetPath);
                }
            }
        }
        
        self::removeDirectory($source);
    }
    
    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
    
    private static function createWwwDirectories(string $wwwDir): void
    {
        $directories = [
            $wwwDir . '/src/Controller',
            $wwwDir . '/src/Entity',
            $wwwDir . '/src/Middleware',
            $wwwDir . '/src/Repository',
            $wwwDir . '/storage/logs',
            $wwwDir . '/migrations',
        ];
        
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
        
        file_put_contents($wwwDir . '/src/Controller/.gitkeep', '');
        file_put_contents($wwwDir . '/src/Entity/.gitkeep', '');
        file_put_contents($wwwDir . '/src/Middleware/.gitkeep', '');
        file_put_contents($wwwDir . '/src/Repository/.gitkeep', '');
    }
    
    private static function createConfigDatabase(string $wwwDir): void
    {
        $configDir = $wwwDir . '/config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        
        $content = <<<'PHP'
<?php

$defaults = [
    'DB_HOST' => 'mariadb_app',
    'DB_PORT' => '3306',
    'DB_NAME' => 'app_db',
    'DB_USER' => 'app_user',
    'DB_PASS' => 'app_password'
];

$getEnv = function(string $key, string $default = '') use ($defaults): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $defaults[$key] ?? $default;
    }
    return $value;
};

return [
    'driver' => 'mysql',
    'host' => $getEnv('DB_HOST'),
    'port' => $getEnv('DB_PORT'),
    'dbname' => $getEnv('DB_NAME'),
    'user' => $getEnv('DB_USER'),
    'password' => $getEnv('DB_PASS'),
];
PHP;
        
        file_put_contents($configDir . '/database.php', $content);
    }
    
    private static function copyComposerJson(string $baseDir, string $wwwDir, bool $hasDoctrine, bool $hasAuth): void
    {
        $projectName = basename($baseDir);
        $targetComposer = $wwwDir . '/composer.json';
        
        $require = [
            'php' => '^8.1',
            'julienlinard/core-php' => '^1.0',
            'julienlinard/php-router' => '^1.0'
        ];
        
        if ($hasDoctrine) {
            $require['julienlinard/doctrine-php'] = '^1.0';
        }
        
        if ($hasAuth) {
            $require['julienlinard/auth-php'] = '^1.0';
        }
        
        $json = [
            'name' => $projectName . '/' . $projectName,
            'require' => $require,
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/'
                ]
            ]
        ];
        
        $content = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($targetComposer, $content);
    }
    
    private static function createHomeView(string $homeDir): void
    {
        $content = <<<'PHP'
<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto bg-white rounded-lg shadow-lg p-8">
        <h1 class="text-4xl font-bold text-gray-800 mb-4"><?= htmlspecialchars($title ?? 'Welcome') ?></h1>
        <p class="text-xl text-gray-600 mb-6"><?= htmlspecialchars($message ?? 'Hello World!') ?></p>
        
        <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
            <p class="text-blue-700">
                <strong>🎉 Congratulations!</strong> Your PHP application is running successfully.
            </p>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-gray-50 p-4 rounded">
                <h2 class="font-semibold text-gray-800 mb-2">📦 Installed Packages</h2>
                <ul class="text-sm text-gray-600 space-y-1">
                    <li>✅ Core PHP Framework</li>
                    <li>✅ PHP Router</li>
                </ul>
            </div>
            <div class="bg-gray-50 p-4 rounded">
                <h2 class="font-semibold text-gray-800 mb-2">🚀 Next Steps</h2>
                <ul class="text-sm text-gray-600 space-y-1">
                    <li>Create your controllers</li>
                    <li>Add your views</li>
                    <li>Configure your database</li>
                </ul>
            </div>
        </div>
    </div>
</div>
PHP;
        
        file_put_contents($homeDir . '/index.html.php', $content);
    }
    
    private static function setupLocal(): void
    {
        echo "\n💻 Configuration locale...\n";
        echo "✅ Configuration locale prête.\n";
    }
    
    private static function configureEnv(): void
    {
        echo "\n⚙️  Configuration de l'environnement (.env)...\n";
        
        $envData = [];
        
        $envData['APACHE_CONTAINER'] = self::askInput('Nom du container Apache', 'apache_app');
        $envData['APACHE_PORT'] = self::askInput('Port Apache', '80');
        $envData['MARIADB_CONTAINER'] = self::askInput('Nom du container MariaDB', 'mariadb_app');
        $envData['MARIADB_PORT'] = self::askInput('Port MariaDB', '3306');
        $envData['MYSQL_ROOT_PASSWORD'] = self::askInput('Mot de passe root MariaDB', 'root');
        $envData['MYSQL_DATABASE'] = self::askInput('Nom de la base de données', 'app_db');
        $envData['MYSQL_USER'] = self::askInput('Utilisateur MariaDB', 'app_user');
        $envData['MYSQL_PASSWORD'] = self::askInput('Mot de passe utilisateur MariaDB', 'app_password');
        $envData['PHP_ERROR_REPORTING'] = self::askInput('PHP Error Reporting (E_ALL)', 'E_ALL');
        $envData['PHP_DISPLAY_ERRORS'] = self::askInput('PHP Display Errors (On/Off)', 'On');
        
        self::createEnvFile($envData);
        
        echo "✅ Fichier .env créé.\n";
    }
    
    private static function askInput(string $question, string $default = ''): string
    {
        $defaultText = $default ? " [{$default}]" : '';
        echo "❓ {$question}{$defaultText}: ";
        
        $handle = fopen('php://stdin', 'r');
        if (!$handle) {
            return $default;
        }
        
        $answer = trim((string) fgets($handle));
        fclose($handle);
        
        return empty($answer) ? $default : $answer;
    }
    
    private static function createEnvFile(array $data): void
    {
        $baseDir = self::getProjectRoot();
        $envPath = $baseDir . '/.env';
        
        $content = "# Configuration Docker\n";
        $content .= "# Généré automatiquement par l'installateur\n\n";
        
        foreach ($data as $key => $value) {
            $content .= "{$key}={$value}\n";
        }
        
        file_put_contents($envPath, $content);
    }
    
    private static function createDockerFiles(string $baseDir): void
    {
        self::createDockerCompose($baseDir);
        self::createDockerfile($baseDir);
        self::createCustomPhpIni($baseDir);
        self::createAliases($baseDir);
        self::createDockerignore($baseDir);
    }
    
    private static function createDockerCompose(string $baseDir): void
    {
        $content = <<<'YAML'
services:
  apache_app:
    build: apache
    container_name: ${APACHE_CONTAINER:-apache_app}
    restart: unless-stopped
    ports:
      - "${APACHE_PORT:-80}:80"
    volumes:
      - ./www:/var/www/html
      - ./apache/custom-php.ini:/usr/local/etc/php/conf.d/custom-php.ini
    environment:
      - PHP_ERROR_REPORTING=${PHP_ERROR_REPORTING:-E_ALL}
      - PHP_DISPLAY_ERRORS=${PHP_DISPLAY_ERRORS:-On}
    networks:
      - app_network
    depends_on:
      mariadb_app:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "wget", "--quiet", "--tries=1", "--spider", "http://localhost/"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 40s
    mem_limit: 512m
    mem_reservation: 256m
    cpus: 2.0

  mariadb_app:
    image: mariadb:11.3
    container_name: ${MARIADB_CONTAINER:-mariadb_app}
    restart: unless-stopped
    ports:
      - "${MARIADB_PORT:-3306}:3306"
    environment:
      - MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD:-root}
      - MYSQL_DATABASE=${MYSQL_DATABASE:-app_db}
      - MYSQL_USER=${MYSQL_USER:-app_user}
      - MYSQL_PASSWORD=${MYSQL_PASSWORD:-app_password}
      - MYSQL_ROOT_HOST=${MYSQL_ROOT_HOST:-%}
    volumes:
      - mysql:/var/lib/mysql
      - ./db:/docker-entrypoint-initdb.d
    networks:
      - app_network
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 30s
    mem_limit: 1g
    mem_reservation: 512m
    cpus: 2.0

networks:
  app_network:
    driver: bridge

volumes:
  mysql:
YAML;
        
        file_put_contents($baseDir . '/docker-compose.yml', $content);
    }
    
    private static function createDockerfile(string $baseDir): void
    {
        $apacheDir = $baseDir . '/apache';
        if (!is_dir($apacheDir)) {
            mkdir($apacheDir, 0755, true);
        }
        
        $content = <<<'DOCKERFILE'
FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
  git \
  unzip \
  wget \
  libpng-dev \
  libjpeg-dev \
  libfreetype6-dev \
  libicu-dev \
  curl \
  && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j$(nproc) gd intl mysqli pdo pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf \
  && echo "<Directory /var/www/html/public>\n\
  AllowOverride All\n\
  Require all granted\n\
  </Directory>" >> /etc/apache2/apache2.conf \
  && a2enmod rewrite

COPY custom-php.ini /usr/local/etc/php/conf.d/

RUN pecl install xdebug \
  && docker-php-ext-enable xdebug \
  && rm -rf /tmp/pear

ENV NVM_DIR=/root/.nvm
ENV NODE_VERSION=20
ENV PATH=$NVM_DIR/versions/node/v$NODE_VERSION/bin:$PATH

RUN curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash \
  && /bin/bash -c "source $NVM_DIR/nvm.sh && nvm install $NODE_VERSION && nvm alias default $NODE_VERSION && nvm use default" \
  && rm -rf /tmp/*

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
DOCKERFILE;
        
        file_put_contents($apacheDir . '/Dockerfile', $content);
    }
    
    private static function createCustomPhpIni(string $baseDir): void
    {
        $apacheDir = $baseDir . '/apache';
        if (!is_dir($apacheDir)) {
            mkdir($apacheDir, 0755, true);
        }
        
        $content = <<<'INI'
[PHP]
html_errors=1

upload_max_filesize = 100M
post_max_size = 100M

memory_limit = 256M
max_execution_time = 300
max_input_time = 300

xdebug.mode = develop,debug
xdebug.max_nesting_level = 256
xdebug.show_exception_trace = 0
xdebug.collect_params = 0
xdebug.log = /tmp/xdebug.log

date.timezone = Europe/Paris
INI;
        
        file_put_contents($apacheDir . '/custom-php.ini', $content);
    }
    
    private static function createHtaccess(string $publicDir): void
    {
        $content = <<<'HTACCESS'
RewriteEngine On

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]

<FilesMatch "\.(env|log|ini|conf)$">
    Require all denied
</FilesMatch>
HTACCESS;
        
        file_put_contents($publicDir . '/.htaccess', $content);
    }
    
    private static function createPublicIndex(string $publicDir, bool $hasDoctrine, bool $hasAuth): void
    {
        $wwwDir = dirname($publicDir);
        $controllerDir = $wwwDir . '/src/Controller';
        if (!is_dir($controllerDir)) {
            mkdir($controllerDir, 0755, true);
        }
        
        $indexContent = self::generateIndexContent($hasDoctrine, $hasAuth);
        
        $controllerContent = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controller;

use JulienLinard\Core\Controller\Controller;
use JulienLinard\Router\Attributes\Route;
use JulienLinard\Router\Response;

class HomeController extends Controller
{
    #[Route(path: '/', methods: ['GET'], name: 'home')]
    public function index(): Response
    {
        return $this->view('home/index', [
            'title' => 'Welcome',
            'message' => 'Hello World!'
        ]);
    }
}
PHP;
        
        file_put_contents($publicDir . '/index.php', $indexContent);
        file_put_contents($controllerDir . '/HomeController.php', $controllerContent);
    }
    
    private static function generateIndexContent(bool $hasDoctrine, bool $hasAuth): string
    {
        $content = <<<'PHP'
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use JulienLinard\Core\Application;
use App\Controller\HomeController;
PHP;

        if ($hasDoctrine) {
            $content .= "\nuse JulienLinard\Doctrine\EntityManager;";
        }
        
        if ($hasAuth) {
            $content .= "\nuse JulienLinard\Auth\AuthManager;";
        }
        
        $content .= "\n\n";
        
        if ($hasDoctrine) {
            $content .= <<<'PHP'
$dbConfig = require __DIR__ . '/../config/database.php';

PHP;
        }
        
        $content .= <<<'PHP'
$app = Application::create(__DIR__ . '/..');
$app->setViewsPath(__DIR__ . '/../views');
$app->setPartialsPath(__DIR__ . '/../views/_templates');

if (file_exists(__DIR__ . '/../.env')) {
    $app->loadEnv();
}

$debug = getenv('APP_DEBUG') === 'true' || getenv('APP_DEBUG') === '1';
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', $debug);
}
$app->getConfig()->set('app.debug', $debug);
error_reporting($debug ? E_ALL : 0);
ini_set('display_errors', $debug ? '1' : '0');

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');

$container = $app->getContainer();
PHP;

        if ($hasDoctrine) {
            $content .= <<<'PHP'

$container->singleton(EntityManager::class, function() use ($dbConfig) {
    return new EntityManager($dbConfig);
});
PHP;
        }
        
        if ($hasAuth) {
            if ($hasDoctrine) {
                $content .= <<<'PHP'

$container->singleton(AuthManager::class, function() use ($container) {
    $em = $container->make(EntityManager::class);
    return new AuthManager([
        'user_class' => \App\Entity\User::class,
        'entity_manager' => $em
    ]);
});
PHP;
            } else {
                $content .= <<<'PHP'

$container->singleton(AuthManager::class, function() {
    return new AuthManager([
        'user_class' => \App\Entity\User::class
    ]);
});
PHP;
            }
        }
        
        $content .= <<<'PHP'

$router = $app->getRouter();
$router->registerRoutes(HomeController::class);

$app->start();
$app->handle();
PHP;
        
        return $content;
    }
    
    private static function createHeaderTemplate(string $templatesDir): void
    {
        $content = <<<'PHP'
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Application') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
PHP;
        
        file_put_contents($templatesDir . '/_header.html.php', $content);
    }
    
    private static function createFooterTemplate(string $templatesDir): void
    {
        $content = <<<'PHP'
</body>
</html>
PHP;
        
        file_put_contents($templatesDir . '/_footer.html.php', $content);
    }
    
    private static function createAliases(string $baseDir): void
    {
        $content = <<<'BASH'
if [ -f .env ]; then
  set -a
  source .env 2>/dev/null || {
    export $(grep -v '^#' .env | grep -v '^$' | grep -v '^[[:space:]]*$' | xargs)
  }
  set +a
fi

APACHE_CONTAINER="${APACHE_CONTAINER:-apache_app}"
MARIADB_CONTAINER="${MARIADB_CONTAINER:-mariadb_app}"

alias ccomposer='docker compose exec ${APACHE_CONTAINER} composer'
alias cconsole='docker compose exec ${APACHE_CONTAINER} symfony console'
alias capache='docker compose exec -it ${APACHE_CONTAINER} bash'
alias cmariadb='docker compose exec -it ${MARIADB_CONTAINER} bash'
alias db-export='docker compose exec ${MARIADB_CONTAINER} /docker-entrypoint-initdb.d/backup.sh'
alias db-import='docker compose exec ${MARIADB_CONTAINER} /docker-entrypoint-initdb.d/restore.sh'
BASH;
        
        file_put_contents($baseDir . '/aliases.sh', $content);
    }
    
    private static function createDockerignore(string $baseDir): void
    {
        $content = <<<'IGNORE'
vendor/
.env
.env.local
.git/
.gitignore
.idea/
.vscode/
*.log
.DS_Store
node_modules/
IGNORE;
        
        file_put_contents($baseDir . '/.dockerignore', $content);
    }
    
    private static function displayCompletion(bool $useDocker): void
    {
        echo "\n";
        echo "╔═══════════════════════════════════════════════════════════╗\n";
        echo "║              Installation terminée avec succès !          ║\n";
        echo "╚═══════════════════════════════════════════════════════════╝\n";
        echo "\n";
        echo "📝 Prochaines étapes:\n";
        
        if ($useDocker) {
            echo "   1. Chargez les aliases: source aliases.sh\n";
            echo "   2. Démarrez Docker: docker compose up -d\n";
            echo "   3. Installez les dépendances: cd www && composer install\n";
            echo "   4. Visitez http://localhost (ou le port configuré)\n";
            echo "   5. Utilisez 'ccomposer' pour les commandes Composer dans Docker\n";
        } else {
            echo "   1. Configurez votre fichier .env si nécessaire\n";
            echo "   2. Installez les dépendances: composer install\n";
            echo "   3. Lancez votre serveur: php -S localhost:8000 -t public\n";
            echo "   4. Visitez http://localhost:8000\n";
        }
        
        echo "\n";
        echo "📚 Documentation:\n";
        echo "   - Router: https://packagist.org/packages/julienlinard/php-router\n";
        echo "   - Core: https://packagist.org/packages/julienlinard/core-php\n";
        echo "\n";
    }
}

