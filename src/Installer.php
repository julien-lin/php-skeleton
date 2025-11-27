<?php

declare(strict_types=1);

namespace Julien;

class Installer
{
    public static function postInstall(): void
    {
        self::displayWelcome();
        
        $useDocker = self::askQuestion('Voulez-vous utiliser Docker ? (y/N)', false);
        
        $installDoctrine = self::askQuestion('Voulez-vous installer Doctrine ? (y/N)', false);
        $installAuth = self::askQuestion('Voulez-vous installer Auth ? (y/N)', false);
        
        $baseDir = self::getProjectRoot();
        $wwwDir = $useDocker ? $baseDir . '/www' : $baseDir;
        
        if ($useDocker) {
            self::setupDocker($installDoctrine, $installAuth);
            self::configureEnv();
        } else {
            self::setupLocal($installDoctrine, $installAuth);
        }
        
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
        
        // Régénérer l'autoloader après la création des fichiers
        self::regenerateAutoloader($wwwDir);
        
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
    
    private static function regenerateAutoloader(string $targetDir): void
    {
        echo "\n🔄 Régénération de l'autoloader...\n";
        
        $composerPath = self::findComposer();
        if (!$composerPath) {
            echo "⚠️  Composer n'est pas disponible. Régénérez manuellement:\n";
            echo "   cd " . basename($targetDir) . " && composer dump-autoload\n";
            return;
        }
        
        $command = 'cd ' . escapeshellarg($targetDir) . ' && ' . escapeshellarg($composerPath) . ' dump-autoload --no-interaction 2>&1';
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            echo "✅ Autoloader régénéré avec succès.\n";
        } else {
            echo "⚠️  Erreur lors de la régénération de l'autoloader.\n";
            echo "   Régénérez manuellement: cd " . basename($targetDir) . " && composer dump-autoload\n";
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
    
    private static function setupDocker(bool $installDoctrine, bool $installAuth): void
    {
        echo "\n🐳 Configuration Docker...\n";
        
        $baseDir = self::getProjectRoot();
        
        self::createWwwStructure($baseDir, $installDoctrine, $installAuth);
        self::createDockerFiles($baseDir);
        
        echo "✅ Fichiers Docker créés.\n";
    }
    
    private static function getProjectRoot(): string
    {
        return getcwd() ?: dirname(__DIR__, 1);
    }
    
    private static function createWwwStructure(string $baseDir, bool $installDoctrine, bool $installAuth): void
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
        self::createBootstrapServices($wwwDir);
        self::createPublicIndex($publicDir, $installDoctrine, $installAuth);
        self::createWwwGitignore($wwwDir);
        
        echo "✅ Structure www/ créée.\n";
    }
    
    private static function moveExistingFiles(string $baseDir, string $wwwDir): void
    {
        // Déplacer uniquement les dossiers nécessaires (pas templates car on utilise views/_templates)
        $filesToMove = ['public', 'src', 'config', 'vendor'];
        
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
        // Supprimer les dossiers et fichiers qui ont été déplacés ou qui ne doivent pas être dans le skeleton généré
        $filesToRemove = [
            'public', 
            'src', 
            'templates',  // Dossier inutilisé (on utilise views/_templates)
            'config', 
            'vendor', 
            'composer.lock',
            // Fichiers du skeleton source qui ne doivent pas être dans le projet généré
            'LICENSE',
            'README.md',
            'README.fr.md',
            'composer.json'  // Le composer.json du skeleton source, pas celui généré
        ];
        
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
            $wwwDir . '/src/Service',
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
        file_put_contents($wwwDir . '/src/Service/.gitkeep', '');
        file_put_contents($wwwDir . '/storage/logs/.gitkeep', '');
        file_put_contents($wwwDir . '/migrations/.gitkeep', '');
    }
    
    private static function createBootstrapServices(string $baseDir): void
    {
        $serviceDir = $baseDir . '/src/Service';
        if (!is_dir($serviceDir)) {
            mkdir($serviceDir, 0755, true);
        }
        
        self::createEnvValidator($serviceDir);
        self::createEventListenerService($serviceDir);
        self::createBootstrapService($serviceDir);
    }
    
    private static function createEnvValidator(string $serviceDir): void
    {
        $content = <<<'PHP'
<?php

/**
 * ============================================
 * ENV VALIDATOR SERVICE
 * ============================================
 * 
 * Service de validation des variables d'environnement
 * Centralise toute la logique de validation pour une meilleure maintenabilité
 */

declare(strict_types=1);

namespace App\Service;

class EnvValidator
{
    /**
     * Valide toutes les variables d'environnement requises
     * 
     * @throws \RuntimeException Si une variable requise est manquante ou invalide
     */
    public static function validate(): void
    {
        self::validateAppSecret();
        self::validateAppLocale();
    }
    
    /**
     * Valide APP_SECRET
     * 
     * @throws \RuntimeException Si APP_SECRET est manquant ou trop court
     */
    private static function validateAppSecret(): void
    {
        $appSecret = getenv('APP_SECRET');
        
        if (empty($appSecret)) {
            throw new \RuntimeException(
                "APP_SECRET n'est pas défini dans votre fichier .env. " .
                "Ce secret est utilisé pour la sécurité (sessions, tokens CSRF, etc.). " .
                "Générez-en un avec: php -r 'echo bin2hex(random_bytes(32)) . PHP_EOL;'"
            );
        }
        
        if (strlen($appSecret) < 32) {
            throw new \RuntimeException(
                "APP_SECRET doit contenir au moins 32 caractères pour la sécurité. " .
                "Générez-en un nouveau avec: php -r 'echo bin2hex(random_bytes(32)) . PHP_EOL;'"
            );
        }
    }
    
    /**
     * Valide APP_LOCALE
     * 
     * @throws \RuntimeException Si APP_LOCALE n'est pas supportée
     */
    private static function validateAppLocale(): void
    {
        $appLocale = getenv('APP_LOCALE') ?: 'fr';
        $supportedLocales = ['fr', 'en', 'es'];
        
        if (!in_array($appLocale, $supportedLocales, true)) {
            throw new \RuntimeException(
                "Locale non supportée: '{$appLocale}'. " .
                "Locales supportées: " . implode(', ', $supportedLocales) . ". " .
                "Définissez APP_LOCALE dans votre fichier .env."
            );
        }
    }
}
PHP;
        
        file_put_contents($serviceDir . '/EnvValidator.php', $content);
    }
    
    private static function createEventListenerService(string $serviceDir): void
    {
        $content = <<<'PHP'
<?php

/**
 * ============================================
 * EVENT LISTENER SERVICE
 * ============================================
 * 
 * Service de gestion des event listeners
 * Centralise l'enregistrement des listeners pour une meilleure organisation
 */

declare(strict_types=1);

namespace App\Service;

use JulienLinard\Core\Events\EventDispatcher;
use JulienLinard\Core\Logging\SimpleLogger;

class EventListenerService
{
    /**
     * Enregistre tous les event listeners de l'application
     * 
     * @param EventDispatcher $events Dispatcher d'événements
     * @param SimpleLogger $logger Logger pour les logs
     */
    public static function register(EventDispatcher $events, SimpleLogger $logger): void
    {
        // Listener pour les requêtes HTTP
        $events->listen('request.started', function(array $payload) use ($logger) {
            $request = $payload['request'];
            $logger->info('Request started', [
                'method' => $request->getMethod(),
                'path' => $request->getPath(),
                'query' => $request->getQueryParams(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        });
        
        // Listener pour les réponses HTTP
        $events->listen('response.sent', function(array $payload) use ($logger) {
            $response = $payload['response'];
            $logger->info('Response sent', [
                'status' => $response->getStatusCode()
            ]);
        });
        
        // Listener pour les exceptions
        $events->listen('exception.thrown', function(array $payload) use ($logger) {
            $exception = $payload['exception'];
            $logger->error('Exception thrown', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ]);
        });
    }
}
PHP;
        
        file_put_contents($serviceDir . '/EventListenerService.php', $content);
    }
    
    private static function createBootstrapService(string $serviceDir): void
    {
        $content = <<<'PHP'
<?php

/**
 * ============================================
 * BOOTSTRAP SERVICE
 * ============================================
 * 
 * Service de bootstrap de l'application
 * Centralise la configuration et l'initialisation pour une meilleure organisation
 */

declare(strict_types=1);

namespace App\Service;

use JulienLinard\Core\Application;
use JulienLinard\Core\ErrorHandler;
use JulienLinard\Core\Logging\SimpleLogger;

class BootstrapService
{
    /**
     * Configure le mode debug et les paramètres PHP
     * 
     * @param Application $app Instance de l'application
     * @return bool Mode debug activé ou non
     */
    public static function configureDebug(Application $app): bool
    {
        $debug = getenv('APP_DEBUG') === 'true' || getenv('APP_DEBUG') === '1';
        
        if (!defined('APP_DEBUG')) {
            define('APP_DEBUG', $debug);
        }
        
        $app->getConfig()->set('app.debug', $debug);
        error_reporting($debug ? E_ALL : 0);
        ini_set('display_errors', $debug ? '1' : '0');
        
        return $debug;
    }
    
    /**
     * Configure la sécurité des sessions
     */
    public static function configureSessionSecurity(): void
    {
        // cookie_httponly : Empêche l'accès au cookie via JavaScript (protection XSS)
        ini_set('session.cookie_httponly', '1');
        
        // cookie_samesite : Empêche l'envoi du cookie lors de requêtes cross-site (protection CSRF)
        ini_set('session.cookie_samesite', 'Strict');
        
        // use_strict_mode : Empêche la fixation de session (attaque de fixation de session)
        ini_set('session.use_strict_mode', '1');
        
        // cookie_secure : Uniquement en production avec HTTPS
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    }
    
    /**
     * Configure l'ErrorHandler avec logging
     * 
     * @param Application $app Instance de l'application
     * @param bool $debug Mode debug
     * @param string $viewsPath Chemin vers les vues
     * @return SimpleLogger Logger configuré
     */
    public static function configureErrorHandler(Application $app, bool $debug, string $viewsPath): SimpleLogger
    {
        $logFile = dirname($viewsPath) . '/storage/logs/app.log';
        $logDir = dirname($logFile);
        
        // Créer le répertoire de logs s'il n'existe pas
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true)) {
                throw new \RuntimeException(
                    "Impossible de créer le répertoire de logs '{$logDir}'. " .
                    "Vérifiez les permissions du répertoire parent."
                );
            }
        }
        
        // Vérifier que le répertoire est accessible en écriture
        if (!is_writable($logDir)) {
            // Essayer de corriger les permissions
            @chmod($logDir, 0755);
            if (!is_writable($logDir)) {
                throw new \RuntimeException(
                    "Le répertoire de logs '{$logDir}' n'est pas accessible en écriture. " .
                    "Veuillez vérifier les permissions (chmod 755 recommandé)."
                );
            }
        }
        
        $logger = new SimpleLogger($logFile);
        $errorHandler = new \JulienLinard\Core\ErrorHandler($app, $logger, $debug, $viewsPath);
        $app->setErrorHandler($errorHandler);
        
        return $logger;
    }
}
PHP;
        
        file_put_contents($serviceDir . '/BootstrapService.php', $content);
    }
    
    private static function createConfigDatabase(string $wwwDir): void
    {
        $configDir = $wwwDir . '/config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        
        $content = <<<'PHP'
<?php

/**
 * Configuration de la base de données
 * 
 * SÉCURITÉ : Les identifiants sensibles (user, password, database) DOIVENT
 * être définis dans le fichier .env et ne JAMAIS être en dur dans le code.
 * 
 * Seules les valeurs non sensibles peuvent avoir des valeurs par défaut.
 */

// Valeurs par défaut uniquement pour les paramètres non sensibles
$defaults = [
    'MARIADB_CONTAINER' => 'mariadb_app', // Nom du container Docker (non sensible)
    'MARIADB_PORT' => '3306', // Port par défaut MySQL (non sensible)
];

/**
 * Récupère une variable d'environnement avec une valeur par défaut optionnelle
 * 
 * @param string $key Clé de la variable d'environnement
 * @param string|null $default Valeur par défaut (null = obligatoire)
 * @return string Valeur de la variable d'environnement
 * @throws \RuntimeException Si la variable est obligatoire et non définie
 */
$getEnv = function(string $key, ?string $default = null) use ($defaults): string {
    $value = getenv($key);
    
    // Si la variable n'est pas définie ou vide
    if ($value === false || $value === '') {
        // Si une valeur par défaut existe (non sensible), l'utiliser
        if (isset($defaults[$key])) {
            return $defaults[$key];
        }
        
        // Si une valeur par défaut est fournie, l'utiliser
        if ($default !== null) {
            return $default;
        }
        
        // Sinon, la variable est obligatoire → lever une exception
        throw new \RuntimeException(
            "Variable d'environnement obligatoire non définie: {$key}. " .
            "Veuillez la définir dans votre fichier .env"
        );
    }
    
    return $value;
};

// Variables sensibles : DOIVENT être définies dans .env (pas de valeur par défaut)
$dbName = $getEnv('MYSQL_DATABASE');
$dbUser = $getEnv('MYSQL_USER');
$dbPassword = $getEnv('MYSQL_PASSWORD');

// Variables non sensibles : peuvent avoir des valeurs par défaut
// IMPORTANT : Dans Docker, le host doit être le nom du service (mariadb_app), pas localhost
$dbHost = $getEnv('MARIADB_CONTAINER', 'mariadb_app');
$dbPort = $getEnv('MARIADB_PORT', '3306');

// Convertir le port en int si c'est une string
$dbPort = is_numeric($dbPort) ? (int)$dbPort : 3306;

// Validation : s'assurer que le host n'est pas localhost en Docker
// (cela ne fonctionnerait pas car chaque container a son propre localhost)
if ($dbHost === 'localhost' || $dbHost === '127.0.0.1') {
    throw new \RuntimeException(
        "Le host de la base de données ne peut pas être 'localhost' ou '127.0.0.1' dans Docker. " .
        "Utilisez le nom du service Docker (ex: 'mariadb_app') ou définissez MARIADB_CONTAINER dans votre .env"
    );
}
return [
    'driver' => 'mysql',
    'host' => $dbHost,
    'port' => $dbPort,
    'dbname' => $dbName,
    'user' => $dbUser,
    'password' => $dbPassword,
    'charset' => 'utf8mb4',
];
PHP;
        
        file_put_contents($configDir . '/database.php', $content);
    }
    
    private static function copyComposerJson(string $baseDir, string $targetDir, bool $hasDoctrine, bool $hasAuth): void
    {
        $projectName = basename($baseDir);
        $targetComposer = $targetDir . '/composer.json';
        
        $require = [
            'php' => '^8.1',
            'julienlinard/core-php' => '^1.0',
            'julienlinard/php-router' => '^1.0',
            'julienlinard/php-validator' => '^1.0'
        ];
        
        if ($hasDoctrine) {
            $require['julienlinard/doctrine-php'] = '^1.0';
        }
        
        if ($hasAuth) {
            $require['julienlinard/auth-php'] = '^1.0';
        }
        
        // Normaliser le nom du projet (minuscules, remplacer espaces et caractères spéciaux par des tirets)
        $normalizedName = strtolower(preg_replace('/[^a-z0-9]+/', '-', $projectName));
        $normalizedName = trim($normalizedName, '-');
        
        $json = [
            'name' => 'your-vendor/' . $normalizedName,
            'description' => 'PHP application built with JulienLinard PHP Framework',
            'type' => 'project',
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
    
    private static function createWwwGitignore(string $wwwDir): void
    {
        $content = <<<'GITIGNORE'
/vendor
/.env
/.env.local
/.env.*.local
!.env.example
/storage/logs/*.log
*.log
.DS_Store
GITIGNORE;
        
        file_put_contents($wwwDir . '/.gitignore', $content);
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
    
    private static function setupLocal(bool $installDoctrine, bool $installAuth): void
    {
        echo "\n💻 Configuration locale...\n";
        $baseDir = self::getProjectRoot();
        self::createLocalStructure($baseDir, $installDoctrine, $installAuth);
        echo "✅ Configuration locale prête.\n";
    }
    
    private static function createLocalStructure(string $baseDir, bool $installDoctrine, bool $installAuth): void
    {
        $publicDir = $baseDir . '/public';
        $viewsDir = $baseDir . '/views';
        $templatesDir = $viewsDir . '/_templates';
        $homeDir = $viewsDir . '/home';
        
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
        
        self::createHtaccess($publicDir);
        self::createHeaderTemplate($templatesDir);
        self::createFooterTemplate($templatesDir);
        self::createHomeView($homeDir);
        self::createLocalDirectories($baseDir);
        self::createConfigDatabase($baseDir);
        self::createBootstrapServices($baseDir);
        self::createWwwGitignore($baseDir);
        
        self::createPublicIndex($publicDir, $installDoctrine, $installAuth);
        
        echo "✅ Structure locale créée.\n";
    }
    
    private static function createLocalDirectories(string $baseDir): void
    {
        $directories = [
            $baseDir . '/src/Controller',
            $baseDir . '/src/Entity',
            $baseDir . '/src/Middleware',
            $baseDir . '/src/Repository',
            $baseDir . '/src/Service',
            $baseDir . '/storage/logs',
            $baseDir . '/migrations',
        ];
        
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
        
        file_put_contents($baseDir . '/src/Controller/.gitkeep', '');
        file_put_contents($baseDir . '/src/Entity/.gitkeep', '');
        file_put_contents($baseDir . '/src/Middleware/.gitkeep', '');
        file_put_contents($baseDir . '/src/Repository/.gitkeep', '');
        file_put_contents($baseDir . '/src/Service/.gitkeep', '');
        file_put_contents($baseDir . '/storage/logs/.gitkeep', '');
        file_put_contents($baseDir . '/migrations/.gitkeep', '');
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
        $wwwEnvPath = $baseDir . '/www/.env';
        
        // Créer le .env à la racine (pour Docker)
        $content = "# Configuration Docker\n";
        $content .= "# Généré automatiquement par l'installateur\n\n";
        
        foreach ($data as $key => $value) {
            $content .= "{$key}={$value}\n";
        }
        
        file_put_contents($envPath, $content);
        
        // Créer le .env dans www/ (pour l'application)
        $wwwContent = "# Configuration Application\n";
        $wwwContent .= "# Généré automatiquement par l'installateur\n\n";
        $wwwContent .= "MARIADB_CONTAINER={$data['MARIADB_CONTAINER']}\n";
        $wwwContent .= "MYSQL_DATABASE={$data['MYSQL_DATABASE']}\n";
        $wwwContent .= "MYSQL_USER={$data['MYSQL_USER']}\n";
        $wwwContent .= "MYSQL_PASSWORD={$data['MYSQL_PASSWORD']}\n";
        $wwwContent .= "PHP_ERROR_REPORTING={$data['PHP_ERROR_REPORTING']}\n";
        $wwwContent .= "PHP_DISPLAY_ERRORS={$data['PHP_DISPLAY_ERRORS']}\n";
        $wwwContent .= "\n";
        $wwwContent .= "# Configuration Application\n";
        $wwwContent .= "APP_SECRET=" . bin2hex(random_bytes(32)) . "\n";
        $wwwContent .= "APP_DEBUG=1\n";
        $wwwContent .= "APP_LOCALE=fr\n";
        
        // Créer le dossier www/ s'il n'existe pas
        $wwwDir = dirname($wwwEnvPath);
        if (!is_dir($wwwDir)) {
            mkdir($wwwDir, 0755, true);
        }
        
        file_put_contents($wwwEnvPath, $wwwContent);
        
        // Créer le fichier .env.example
        self::createEnvExample($baseDir, $wwwDir);
    }
    
    private static function createEnvExample(string $baseDir, string $wwwDir): void
    {
        $envExamplePath = $baseDir . '/.env.example';
        $wwwEnvExamplePath = $wwwDir . '/.env.example';
        
        // .env.example à la racine (pour Docker)
        $rootContent = <<<'ENV'
# ============================================
# CONFIGURATION DOCKER COMPOSE
# ============================================
# Ce fichier configure les containers Docker (ports EXTERNES, noms, etc.)
# Utilisé par docker-compose.yml
#
# IMPORTANT : Les ports ici sont les ports EXPOSÉS sur l'hôte (ports externes)
# Exemple : MARIADB_PORT=3306 signifie que le port 3306 de l'hôte est mappé au container
#
# Copiez ce fichier en .env et modifiez les valeurs selon vos besoins

APACHE_CONTAINER=apache_app
APACHE_PORT=80
MARIADB_CONTAINER=mariadb_app
MARIADB_PORT=3306
MYSQL_ROOT_PASSWORD=root
MYSQL_DATABASE=app_db
MYSQL_USER=app_user
MYSQL_PASSWORD=app_password
PHP_ERROR_REPORTING=E_ALL
PHP_DISPLAY_ERRORS=On
ENV;
        
        file_put_contents($envExamplePath, $rootContent);
        
        // .env.example dans www/ (pour l'application)
        $wwwContent = <<<'ENV'
# ============================================
# CONFIGURATION APPLICATION PHP
# ============================================
# Ce fichier configure l'application PHP qui tourne DANS le container
#
# IMPORTANT : Les ports ici sont les ports INTERNES du réseau Docker
# - MARIADB_CONTAINER : Nom du service Docker (pour la connexion interne)
# - Le port MariaDB est toujours 3306 (port interne du container)
# - Host = nom du service Docker (mariadb_app) pour la connexion interne
#
# Copiez ce fichier en .env et modifiez les valeurs selon vos besoins

# ============================================
# Configuration Base de données
# ============================================
# Ces variables sont utilisées par l'application PHP pour se connecter à MariaDB
# depuis l'intérieur du réseau Docker
MARIADB_CONTAINER=mariadb_app
MYSQL_DATABASE=app_db
MYSQL_USER=app_user
MYSQL_PASSWORD=app_password
PHP_ERROR_REPORTING=E_ALL
PHP_DISPLAY_ERRORS=On

# ============================================
# Configuration Application
# ============================================
# APP_SECRET : Secret utilisé pour la sécurité (sessions, tokens CSRF, etc.)
# Générez un secret sécurisé avec: php -r 'echo bin2hex(random_bytes(32)) . PHP_EOL;'
# DOIT contenir au moins 32 caractères
APP_SECRET=

# APP_DEBUG : Mode debug (1 = activé, 0 = désactivé)
# En production, mettre à 0 pour la sécurité
APP_DEBUG=1

# APP_LOCALE : Locale de l'application (fr, en, es)
APP_LOCALE=fr
ENV;
        
        // Créer le dossier www/ s'il n'existe pas
        if (!is_dir($wwwDir)) {
            mkdir($wwwDir, 0755, true);
        }
        
        file_put_contents($wwwEnvExamplePath, $wwwContent);
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
      timeout: 10s
      retries: 10
      start_period: 90s
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

/**
 * ============================================
 * HOME CONTROLLER
 * ============================================
 * 
 * CONCEPT PÉDAGOGIQUE : Controller simple
 * 
 * Ce contrôleur gère la route racine "/" et affiche la page d'accueil.
 */

declare(strict_types=1);

namespace App\Controller;

use JulienLinard\Core\Controller\Controller;
use JulienLinard\Router\Attributes\Route;
use JulienLinard\Router\Response;

class HomeController extends Controller
{
    /**
     * Route racine : affiche la page d'accueil
     * 
     * CONCEPT : Route simple sans middleware
     */
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

/**
 * ============================================
 * POINT D'ENTRÉE DE L'APPLICATION (Bootstrap)
 * ============================================
 * 
 * Ce fichier est le point d'entrée unique de l'application.
 * Il initialise tous les composants nécessaires au fonctionnement de l'app.
 * 
 * CONCEPT PÉDAGOGIQUE : Bootstrap Pattern
 * Le bootstrap est le code qui initialise l'application avant son exécution.
 * C'est ici que l'on configure les services, les routes, et les middlewares.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use JulienLinard\Core\Application;
use JulienLinard\Core\Middleware\CsrfMiddleware;
use JulienLinard\Validator\Validator as PhpValidator;
use JulienLinard\Core\Form\Validator as CoreValidator;
use App\Controller\HomeController;
use App\Service\EnvValidator;
use App\Service\EventListenerService;
use App\Service\BootstrapService;
PHP;

        if ($hasDoctrine) {
            $content .= "\nuse JulienLinard\Doctrine\EntityManager;";
        }
        
        if ($hasAuth) {
            $content .= "\nuse JulienLinard\Auth\AuthManager;";
        }
        
        $content .= "\n\n";
        
        $content .= <<<'PHP'
// ============================================
// ÉTAPE 1 : CRÉATION DE L'APPLICATION
// ============================================
// Créer l'instance de l'application
// CONCEPT : Application Singleton
$app = Application::create(dirname(__DIR__));

// ============================================
// ÉTAPE 2 : CHARGEMENT DES VARIABLES D'ENVIRONNEMENT
// ============================================
// IMPORTANT : Charger .env AVANT la configuration
// Les fichiers de configuration (comme database.php) ont besoin des variables d'environnement
// CONCEPT : Variables d'environnement pour la sécurité (identifiants, secrets)
try {
    $app->loadEnv();
} catch (\Exception $e) {
    throw new \RuntimeException(
        "Erreur lors du chargement du fichier .env: " . $e->getMessage() . "\n" .
        "Veuillez créer un fichier .env dans le répertoire www/ avec les variables nécessaires.\n" .
        "Consultez .env.example pour un exemple."
    );
}

// ============================================
// ÉTAPE 3 : CHARGEMENT DE LA CONFIGURATION
// ============================================
// Charger la configuration depuis le répertoire config/
// CONCEPT : Configuration centralisée avec ConfigLoader
// Tous les fichiers PHP dans config/ sont automatiquement chargés
// Les fichiers de configuration peuvent maintenant utiliser getenv() pour lire les variables
$app->loadConfig('config');
PHP;

        if ($hasDoctrine) {
            $content .= <<<'PHP'

$dbConfig = $app->getConfig()->get('database', []);
PHP;
        }
        
        $content .= <<<'PHP'

// ============================================
// ÉTAPE 4 : INITIALISATION DE L'APPLICATION
// ============================================
// Définir les chemins des vues (templates)
// CONCEPT : Configuration des chemins pour le moteur de templates
$app->setViewsPath(__DIR__ . '/../views');
$app->setPartialsPath(__DIR__ . '/../views/_templates');

// ============================================
// ÉTAPE 5 : VALIDATION DES VARIABLES D'ENVIRONNEMENT
// ============================================
// Valider toutes les variables d'environnement requises
// CONCEPT : Validation centralisée pour une meilleure maintenabilité
EnvValidator::validate();

// ============================================
// ÉTAPE 6 : CONFIGURATION DU MODE DEBUG ET ERROR HANDLER
// ============================================
// Activer le mode debug selon la variable d'environnement
// CONCEPT : Environnements (dev/prod)
// En développement : afficher les erreurs pour déboguer
// En production : masquer les erreurs pour la sécurité
$debug = BootstrapService::configureDebug($app);
$viewsPath = __DIR__ . '/../views';
$logger = BootstrapService::configureErrorHandler($app, $debug, $viewsPath);

// ============================================
// ÉTAPE 7 : CONFIGURATION DE SÉCURITÉ DES SESSIONS
// ============================================
// Ces paramètres sécurisent les cookies de session PHP
// CONCEPT : Sécurité des sessions (XSS, CSRF, fixation de session)
BootstrapService::configureSessionSecurity();

// ============================================
// ÉTAPE 9 : CONFIGURATION DU CONTAINER DI
// ============================================
// Récupérer le container d'injection de dépendances
// CONCEPT PÉDAGOGIQUE : Dependency Injection (DI) Container
// Le container gère la création et l'injection des dépendances
// Permet de découpler le code et facilite les tests
$container = $app->getContainer();
PHP;

        if ($hasDoctrine) {
            $content .= <<<'PHP'

// Enregistrer EntityManager comme singleton
// CONCEPT : Singleton = une seule instance partagée dans toute l'application
// Utile pour les services coûteux (connexion DB, etc.)
$container->singleton(EntityManager::class, function() use ($dbConfig) {
    return new EntityManager($dbConfig);
});
PHP;
        }
        
        if ($hasAuth) {
            if ($hasDoctrine) {
                $content .= <<<'PHP'

// Enregistrer AuthManager comme singleton
// Le AuthManager a besoin de l'EntityManager, donc on l'injecte via le container
// CONCEPT : Injection de dépendances - AuthManager dépend d'EntityManager
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

// Enregistrer AuthManager comme singleton
$container->singleton(AuthManager::class, function() {
    return new AuthManager([
        'user_class' => \App\Entity\User::class
    ]);
});
PHP;
            }
        }
        
        $content .= <<<'PHP'

// Enregistrer Validator (php-validator) comme singleton avec la locale de l'application
// CONCEPT : Configuration centralisée de la locale pour les messages d'erreur multilingues
// La locale est validée par EnvValidator (déjà appelé plus haut)
$appLocale = getenv('APP_LOCALE') ?: 'fr';
$container->singleton(PhpValidator::class, function() use ($appLocale) {
    return new PhpValidator($appLocale);
});

// Enregistrer CoreValidator comme singleton (utilise php-validator en interne)
// CONCEPT : CoreValidator est un wrapper autour de php-validator utilisé par les contrôleurs
$container->singleton(CoreValidator::class, function() use ($container) {
    $phpValidator = $container->make(PhpValidator::class);
    $coreValidator = new CoreValidator();
    // Configurer la locale du CoreValidator pour qu'elle corresponde à php-validator
    $coreValidator->setLocale($phpValidator->getLocale());
    return $coreValidator;
});

// Enregistrer FileUploadService comme singleton (si la classe existe)
// CONCEPT : Service d'upload de fichiers avec validation intégrée
// Note : Ce service doit être créé dans src/Service/FileUploadService.php si nécessaire
if (class_exists(\App\Service\FileUploadService::class)) {
    $container->singleton(\App\Service\FileUploadService::class, function() use ($container) {
        return new \App\Service\FileUploadService();
    });
}

// ============================================
// ÉTAPE 10 : CONFIGURATION DU ROUTER ET MIDDLEWARES
// ============================================
// Récupérer le router qui gère les routes de l'application
// CONCEPT PÉDAGOGIQUE : Router (Routeur)
// Le router fait le lien entre les URLs et les méthodes des contrôleurs
$router = $app->getRouter();

// Ajouter le middleware CSRF globalement pour toutes les requêtes
// CONCEPT PÉDAGOGIQUE : Middleware Global
// Un middleware global s'exécute sur TOUTES les requêtes
// Ici, il génère le token CSRF si nécessaire et le vérifie pour POST/PUT/DELETE
// CONCEPT : CSRF Protection (Cross-Site Request Forgery)
// Protection contre les attaques où un site malveillant fait des requêtes en votre nom
$router->addMiddleware(new CsrfMiddleware());

// ============================================
// ÉTAPE 8 : CONFIGURATION DU SYSTÈME D'ÉVÉNEMENTS
// ============================================
// Récupérer le dispatcher d'événements et enregistrer les listeners
// CONCEPT : EventDispatcher pour l'extensibilité
// Permet d'écouter les événements de l'application (request.started, response.sent, etc.)
$events = $app->getEvents();
EventListenerService::register($events, $logger);

// ============================================
// ÉTAPE 11 : ENREGISTREMENT DES ROUTES
// ============================================
// Enregistrer toutes les routes définies dans les contrôleurs
// CONCEPT PÉDAGOGIQUE : Route Attributes (PHP 8)
// Les routes sont définies directement dans les contrôleurs avec des attributs #[Route]
// Le router scanne les contrôleurs et enregistre automatiquement les routes
$router->registerRoutes(HomeController::class);

// Démarrer l'application
$app->start();

// Traiter la requête HTTP
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
    <?php
    // Afficher les messages flash (success et error) depuis la session
    // Les messages sont affichés en haut à droite avec auto-hide après 5 secondes
    use JulienLinard\Core\Session\Session;
    $headerSuccess = Session::getFlash('success');
    $headerError = Session::getFlash('error');
    ?>
    
    <?php if ($headerSuccess): ?>
    <div class="fixed top-4 right-4 z-50 max-w-md w-full">
        <div class="bg-green-50 border-l-4 border-green-500 p-4 rounded-lg shadow-lg">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-green-800"><?= htmlspecialchars($headerSuccess) ?></p>
                </div>
            </div>
        </div>
    </div>
    <script>
        // Auto-hide après 5 secondes
        setTimeout(() => {
            document.querySelector('.bg-green-50')?.parentElement?.remove();
        }, 5000);
    </script>
    <?php endif; ?>
    
    <?php if ($headerError): ?>
    <div class="fixed top-4 right-4 z-50 max-w-md w-full">
        <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-lg shadow-lg">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-red-800"><?= htmlspecialchars($headerError) ?></p>
                </div>
            </div>
        </div>
    </div>
    <script>
        // Auto-hide après 5 secondes
        setTimeout(() => {
            document.querySelector('.bg-red-50')?.parentElement?.remove();
        }, 5000);
    </script>
    <?php endif; ?>
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

