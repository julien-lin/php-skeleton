<?php

declare(strict_types=1);

namespace Julien;

class Installer
{
    /**
     * Stocke les noms de conteneurs configurés pour les utiliser dans docker-compose.yml
     */
    private static ?array $containerNames = null;
    
    public static function postInstall(): void
    {
        self::displayWelcome();
        
        $useDocker = self::askQuestion('Voulez-vous utiliser Docker ? (y/N)', false);
        
        $installDoctrine = self::askQuestion('Voulez-vous installer Doctrine ? (y/N)', false);
        $installAuth = self::askQuestion('Voulez-vous installer Auth ? (y/N)', false);
        $installApi = self::askQuestion('Voulez-vous installer le profil API ? (y/N)', false);
        $installVision = self::askQuestion('Voulez-vous installer le profil Vision ? (y/N)', false);
        $installSecure = self::askQuestion('Voulez-vous activer le profil sécurisé ? (y/N)', false);

        // Auth repose sur Doctrine : empêcher la génération d'un bootstrap incohérent.
        if ($installAuth || $installApi) {
            $installDoctrine = true;
        }
        
        $baseDir = self::getProjectRoot();
        $wwwDir = $useDocker ? $baseDir . '/www' : $baseDir;

        self::assertInstallTargetIsSkeleton($baseDir, $useDocker);
        
        if ($useDocker) {
            // Configurer l'environnement AVANT de créer docker-compose.yml
            // pour avoir les noms de conteneurs
            self::configureEnv($installDoctrine, $installApi);
            self::setupDocker($installDoctrine, $installAuth, $installApi, $installVision, $installSecure);
        } else {
            self::setupLocal($installDoctrine, $installAuth, $installApi, $installVision, $installSecure);
        }
        
        self::copyComposerJson($baseDir, $wwwDir, $installDoctrine, $installAuth, $installApi, $installVision, $installSecure);

        // Le composer.json généré contient déjà le profil choisi : une seule
        // résolution évite les lockfiles intermédiaires et les incohérences.
        self::installDependencies($wwwDir);
        
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

        self::assertPackageName($package);

        [$output, $returnCode] = self::runComposer($baseDir, ['require', $package, '--no-interaction', '--prefer-dist']);

        if ($returnCode === 0) {
            echo "✅ {$package} installé avec succès.\n";
        } else {
            throw new \RuntimeException(
                "Échec de l'installation de {$package}:\n" . implode("\n", $output)
            );
        }
    }
    
    private static function installPackageInDocker(string $package, string $wwwDir): void
    {
        echo "\n📦 Installation de {$package} dans www/...\n";

        if (!is_dir($wwwDir)) {
            throw new \RuntimeException("Le répertoire {$wwwDir} n'existe pas.");
        }

        self::assertPackageName($package);

        [$output, $returnCode] = self::runComposer($wwwDir, ['require', $package, '--no-interaction', '--prefer-dist']);

        if ($returnCode === 0) {
            echo "✅ {$package} installé avec succès dans www/.\n";
        } else {
            throw new \RuntimeException(
                "Échec de l'installation de {$package} dans www/:\n" . implode("\n", $output)
            );
        }
    }
    
    private static function regenerateAutoloader(string $targetDir): void
    {
        echo "\n🔄 Régénération de l'autoloader...\n";

        [$output, $returnCode] = self::runComposer($targetDir, ['dump-autoload', '--no-interaction']);

        if ($returnCode === 0) {
            echo "✅ Autoloader régénéré avec succès.\n";
        } else {
            throw new \RuntimeException(
                "Échec de la régénération de l'autoloader:\n" . implode("\n", $output)
            );
        }
    }

    private static function installDependencies(string $targetDir): void
    {
        echo "\n📦 Installation des dépendances du profil...\n";

        [$output, $returnCode] = self::runComposer(
            $targetDir,
            ['update', '--no-interaction', '--prefer-dist', '--no-dev']
        );

        if ($returnCode !== 0) {
            throw new \RuntimeException(
                "Échec de la résolution des dépendances:\n" . implode("\n", $output)
            );
        }

        echo "✅ Dépendances installées et lockfile généré.\n";
    }

    private static function assertInstallTargetIsSkeleton(string $baseDir, bool $useDocker): void
    {
        $sourceComposerPath = $baseDir . '/composer.json';
        if (!is_file($sourceComposerPath)) {
            throw new \RuntimeException("composer.json introuvable dans le répertoire source.");
        }

        $sourceComposer = json_decode((string) file_get_contents($sourceComposerPath), true);
        if (!is_array($sourceComposer) || ($sourceComposer['name'] ?? null) !== 'julienlinard/php-skeleton') {
            throw new \RuntimeException(
                "Ce répertoire semble déjà contenir une application générée. " .
                "L'installation est interrompue pour protéger ses fichiers."
            );
        }

        if ($useDocker && is_file($baseDir . '/www/composer.json')) {
            throw new \RuntimeException(
                "Le répertoire www/ contient déjà un projet généré. " .
                "L'installation est interrompue pour protéger ses fichiers."
            );
        }
    }

    /**
     * Exécute Composer sans passer par un shell.
     * Les arguments sont transmis sous forme de tableau afin d'éviter toute
     * interpolation de chemin ou d'argument dans une commande shell.
     *
     * @return array{0: array<int, string>, 1: int} Sortie et code retour
     */
    private static function runComposer(string $workingDirectory, array $arguments): array
    {
        if (!is_dir($workingDirectory)) {
            throw new \RuntimeException("Répertoire de travail introuvable: {$workingDirectory}");
        }

        $composerPath = self::findComposer();
        if ($composerPath === null) {
            throw new \RuntimeException(
                "Composer est requis pour terminer l'installation. " .
                "Installez Composer puis relancez l'installateur."
            );
        }

        $command = array_merge([$composerPath], array_values($arguments));
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException("Impossible de démarrer Composer.");
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);
        $output = array_values(array_filter(
            preg_split('/\R/', trim((string)$stdout . "\n" . (string)$stderr)) ?: [],
            static fn(string $line): bool => $line !== ''
        ));

        return [$output, $returnCode];
    }

    private static function assertPackageName(string $package): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/i', $package)) {
            throw new \RuntimeException("Commande non autorisée: package {$package}");
        }
    }
    
    private static function findComposer(): ?string
    {
        $possiblePaths = [
            'composer',
            'composer.phar',
            dirname(__DIR__, 2) . '/composer.phar',
        ];
        
        foreach ($possiblePaths as $path) {
            if (self::isExecutable($path)) {
                return $path;
            }
        }
        
        // ✅ PHASE 1.1: Utiliser safeShellExec au lieu de shell_exec()
        // Note: safeShellExec rejette les redirections (2>/dev/null), donc on les enlève
        try {
            $whichComposer = self::safeShellExec('which composer');
            if (!empty($whichComposer) && self::isExecutable($whichComposer)) {
                return $whichComposer;
            }
        } catch (\RuntimeException $e) {
            // Ignorer les erreurs de sécurité pour which (fallback silencieux)
        }
        
        return null;
    }
    
    /**
     * Exécute une commande de manière sécurisée
     * 
     * ✅ PHASE 1.1: Sécurisation de l'utilisation de exec()
     * 
     * @param string $command Commande à exécuter
     * @param array &$output Sortie de la commande
     * @param int &$returnCode Code de retour
     * @return bool True si la commande a réussi
     * @throws \RuntimeException Si la commande n'est pas autorisée
     */
    private static function safeExec(string $command, array &$output, int &$returnCode): bool
    {
        // Whitelist de commandes autorisées
        $allowedCommands = ['composer', 'which', 'composer.phar'];
        
        // Extraire la commande de base (premier mot)
        $commandParts = preg_split('/\s+/', trim($command), 2);
        $baseCommand = $commandParts[0] ?? '';
        
        // Pour les commandes avec "cd", extraire la commande après "&&"
        if (str_starts_with($command, 'cd ')) {
            $parts = explode(' && ', $command, 2);
            if (isset($parts[1])) {
                $actualCommand = trim($parts[1]);
                $commandParts = preg_split('/\s+/', $actualCommand, 2);
                $baseCommand = $commandParts[0] ?? '';
            }
        }
        
        // Nettoyer la commande (enlever les guillemets)
        $baseCommand = trim($baseCommand, '\'"');
        
        // Extraire le nom de la commande (basename) pour gérer les chemins complets
        // Exemple: /usr/local/bin/composer -> composer
        // Exemple: composer.phar -> composer.phar
        $commandName = basename($baseCommand);
        
        // Vérifier que la commande de base est autorisée
        // On accepte soit le nom exact, soit le basename
        if (!in_array($baseCommand, $allowedCommands, true) && !in_array($commandName, $allowedCommands, true)) {
            throw new \RuntimeException("Commande non autorisée: {$baseCommand}");
        }
        
        // Validation des chemins (protection contre path traversal)
        // Séparer la commande en parties, en tenant compte de && pour les commandes avec cd
        $parts = explode(' && ', $command);
        foreach ($parts as $part) {
            $allParts = preg_split('/\s+/', trim($part));
            foreach ($allParts as $partItem) {
                $cleanPart = trim($partItem, '\'"');
                
                // Ignorer && qui est autorisé dans le contexte de cd
                if ($cleanPart === '&&') {
                    continue;
                }
                
                // Rejeter les chemins avec ..
                if (str_contains($cleanPart, '..')) {
                    throw new \RuntimeException("Chemin non autorisé (path traversal détecté): {$cleanPart}");
                }
                
                // Rejeter les chemins système sensibles
                $forbiddenPaths = ['/etc', '/bin', '/usr/bin', '/sbin', '/usr/sbin', '/var', '/sys', '/proc'];
                foreach ($forbiddenPaths as $forbidden) {
                    if (str_starts_with($cleanPart, $forbidden) && $cleanPart !== $forbidden) {
                        throw new \RuntimeException("Chemin système non autorisé: {$cleanPart}");
                    }
                }
                
                // Rejeter les caractères dangereux (mais autoriser && dans le contexte de cd)
                if (preg_match('/[;&|`$<>]/', $cleanPart) && $cleanPart !== '&&') {
                    throw new \RuntimeException("Caractères dangereux détectés dans: {$cleanPart}");
                }
            }
        }
        
        // Exécuter la commande
        $result = exec($command . ' 2>&1', $output, $returnCode);
        
        return $result !== false;
    }
    
    /**
     * Exécute une commande shell_exec de manière sécurisée
     * 
     * ✅ PHASE 1.1: Sécurisation de l'utilisation de shell_exec()
     * 
     * @param string $command Commande à exécuter
     * @return string|null Sortie de la commande ou null en cas d'erreur
     * @throws \RuntimeException Si la commande n'est pas autorisée
     */
    private static function safeShellExec(string $command): ?string
    {
        // Whitelist de commandes autorisées
        $allowedCommands = ['which'];
        
        // Extraire la commande de base
        $commandParts = preg_split('/\s+/', trim($command), 2);
        $baseCommand = $commandParts[0] ?? '';
        
        // Vérifier que la commande de base est autorisée
        if (!in_array($baseCommand, $allowedCommands, true)) {
            throw new \RuntimeException("Commande non autorisée: {$baseCommand}");
        }
        
        // Validation des arguments
        if (isset($commandParts[1])) {
            $arg = trim($commandParts[1], '\'"');
            
            // Rejeter les chemins avec ..
            if (str_contains($arg, '..')) {
                throw new \RuntimeException("Chemin non autorisé (path traversal détecté): {$arg}");
            }
            
            // Rejeter les caractères dangereux
            if (preg_match('/[;&|`$<>]/', $arg)) {
                throw new \RuntimeException("Caractères dangereux détectés dans: {$arg}");
            }
        }
        
        // Exécuter la commande
        $result = shell_exec($command);
        
        return $result !== null ? trim((string)$result) : null;
    }
    
    private static function isExecutable(string $path): bool
    {
        if ($path === 'composer' || $path === 'composer.phar') {
            // ✅ PHASE 1.1: Utiliser safeShellExec au lieu de shell_exec
            // Note: safeShellExec rejette les redirections (2>/dev/null), donc on les enlève
            try {
                $which = self::safeShellExec('which ' . escapeshellarg($path));
                if (!empty($which)) {
                    return is_executable($which);
                }
            } catch (\RuntimeException $e) {
                // Si safeShellExec rejette la commande, on retourne false
                return false;
            }
            return false;
        }
        
        return file_exists($path) && is_executable($path);
    }
    
    private static function setupDocker(
        bool $installDoctrine,
        bool $installAuth,
        bool $installApi = false,
        bool $installVision = false,
        bool $installSecure = false
    ): void
    {
        echo "\n🐳 Configuration Docker...\n";
        
        $baseDir = self::getProjectRoot();
        
        self::createWwwStructure($baseDir, $installDoctrine, $installAuth, $installApi, $installVision, $installSecure);
        self::createDockerFiles($baseDir, $installDoctrine);
        
        echo "✅ Fichiers Docker créés.\n";
    }
    
    private static function getProjectRoot(): string
    {
        return getcwd() ?: dirname(__DIR__, 1);
    }
    
    private static function createWwwStructure(
        string $baseDir,
        bool $installDoctrine,
        bool $installAuth,
        bool $installApi = false,
        bool $installVision = false,
        bool $installSecure = false
    ): void
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
        self::createHeaderTemplate($templatesDir, $installVision);
        self::createFooterTemplate($templatesDir);
        self::createHomeView($homeDir, $installVision);
        self::createWwwDirectories($wwwDir);
        self::createConfigDatabase($wwwDir, $installDoctrine);
        if ($installAuth) {
            self::createAuthFiles($wwwDir);
        }
        if ($installApi) {
            self::createApiFiles($wwwDir);
        }
        self::createBootstrapServices($wwwDir, $installDoctrine || $installAuth || $installApi);
        self::createPublicIndex($publicDir, $installDoctrine, $installAuth, $installApi, $installSecure);
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
        // Ne supprimer que les éléments explicitement déplacés ou recréés dans
        // www/. La licence, la documentation, le lockfile et les fichiers
        // utilisateur doivent rester récupérables à la racine.
        $filesToRemove = [
            'public',
            'src',
            'templates',
            'config',
            'vendor',
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
        
        // ✅ PHASE 2.1: Supprimer aussi le dossier tests s'il a été copié par erreur
        $testsPath = $wwwDir . '/tests';
        if (is_dir($testsPath)) {
            self::removeDirectory($testsPath);
        }
        
        // Supprimer aussi phpunit.xml s'il a été copié
        $phpunitPath = $wwwDir . '/phpunit.xml';
        if (file_exists($phpunitPath)) {
            unlink($phpunitPath);
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
        $publicDir = $wwwDir . '/public';
        $directories = [
            $wwwDir . '/src/Controller',
            $wwwDir . '/src/Entity',
            $wwwDir . '/src/Middleware',
            $wwwDir . '/src/Repository',
            $wwwDir . '/src/Service',
            $wwwDir . '/storage/logs',
            $wwwDir . '/migrations',
            $publicDir . '/uploads',
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
        file_put_contents($publicDir . '/uploads/.gitkeep', '');
        
        // Fixer les permissions pour Linux (après création de tous les dossiers)
        self::fixPermissions($wwwDir, true);
    }
    
    private static function createBootstrapServices(string $baseDir, bool $requiresDatabase = false): void
    {
        $serviceDir = $baseDir . '/src/Service';
        if (!is_dir($serviceDir)) {
            mkdir($serviceDir, 0755, true);
        }
        
        self::createEnvValidator($serviceDir, $requiresDatabase);
        self::createEventListenerService($serviceDir);
        self::createBootstrapService($serviceDir);
    }
    
    private static function createEnvValidator(string $serviceDir, bool $requiresDatabase): void
    {
        $requiredExtensions = ['mbstring'];
        if ($requiresDatabase) {
            $requiredExtensions[] = 'pdo';
            $requiredExtensions[] = 'pdo_mysql';
        }

        $requiredExtensionsCode = var_export($requiredExtensions, true);

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
        self::validateExtensions();
        self::validateAppEnvironment();
        self::validateAppSecret();
        self::validateAppLocale();
    }

    /**
     * Vérifie les extensions PHP indispensables au profil généré.
     *
     * @throws \RuntimeException Si une extension requise est absente
     */
    private static function validateExtensions(): void
    {
        $requiredExtensions = __REQUIRED_EXTENSIONS__;
        $missingExtensions = array_values(array_filter(
            $requiredExtensions,
            static fn (string $extension): bool => !extension_loaded($extension)
        ));

        if ($missingExtensions !== []) {
            throw new \RuntimeException(
                'Extensions PHP requises manquantes: ' . implode(', ', $missingExtensions) . '. ' .
                'Installez-les avant de démarrer l’application.'
            );
        }
    }

    /**
     * Valide APP_ENV et APP_DEBUG lorsqu’ils sont définis.
     *
     * @throws \RuntimeException Si une valeur d’environnement est invalide
     */
    private static function validateAppEnvironment(): void
    {
        $appEnv = getenv('APP_ENV') ?: 'local';
        $supportedEnvironments = ['local', 'development', 'staging', 'production'];

        if (!in_array($appEnv, $supportedEnvironments, true)) {
            throw new \RuntimeException(
                "Environnement non supporté: '{$appEnv}'. " .
                'Environnements supportés: ' . implode(', ', $supportedEnvironments) . '.'
            );
        }

        $appDebug = getenv('APP_DEBUG');
        if ($appDebug !== false && !in_array($appDebug, ['0', '1', 'true', 'false'], true)) {
            throw new \RuntimeException(
                "APP_DEBUG doit être défini à 0, 1, true ou false; valeur reçue: '{$appDebug}'."
            );
        }
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

        $content = str_replace('__REQUIRED_EXTENSIONS__', $requiredExtensionsCode, $content);
        
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
    
    private static function createConfigDatabase(string $wwwDir, bool $enabled): void
    {
        if (!$enabled) {
            return;
        }

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

// Les variables DB_* sont prioritaires pour le local. Les variables MYSQL_*
// restent acceptées pour conserver la compatibilité avec Docker.
$getFirstEnv = function(array $keys, ?string $default = null) use ($getEnv): string {
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return (string) $value;
        }
    }

    return $getEnv($keys[0], $default);
};

// Variables sensibles : DOIVENT être définies dans .env (pas de valeur par défaut)
$dbName = $getFirstEnv(['DB_NAME', 'MYSQL_DATABASE']);
$dbUser = $getFirstEnv(['DB_USER', 'MYSQL_USER']);
$dbPassword = $getFirstEnv(['DB_PASS', 'MYSQL_PASSWORD']);

// Variables non sensibles : peuvent avoir des valeurs par défaut.
$dbHost = $getFirstEnv(['DB_HOST', 'MARIADB_CONTAINER'], 'mariadb_app');
$dbPort = $getFirstEnv(['DB_PORT', 'MARIADB_PORT'], '3306');

// Valider explicitement le port au lieu de remplacer silencieusement une valeur invalide.
$validatedPort = filter_var(
    $dbPort,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 65535]]
);
if ($validatedPort === false) {
    throw new \RuntimeException(
        "Port de base de données invalide: {$dbPort}. Utilisez un entier compris entre 1 et 65535."
    );
}
$dbPort = $validatedPort;

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
    
    private static function copyComposerJson(
        string $baseDir,
        string $targetDir,
        bool $hasDoctrine,
        bool $hasAuth,
        bool $hasApi = false,
        bool $hasVision = false,
        bool $hasSecure = false
    ): void
    {
        if ($hasAuth || $hasApi) {
            $hasDoctrine = true;
        }

        $projectName = basename($baseDir);
        $targetComposer = $targetDir . '/composer.json';

        if (is_file($targetComposer)) {
            $existing = json_decode((string) file_get_contents($targetComposer), true);
            if (!is_array($existing) || ($existing['name'] ?? null) !== 'julienlinard/php-skeleton') {
                throw new \RuntimeException(
                    "Le fichier {$targetComposer} existe déjà et ne correspond pas au skeleton source."
                );
            }
        }
        
        $require = [
            'php' => '^8.1',
            'julienlinard/core-php' => '^1.4',
            'julienlinard/php-router' => '^1.4'
        ];
        
        if ($hasDoctrine) {
            $require['julienlinard/doctrine-php'] = '^1.2';
            $require['ext-pdo'] = '*';
            $require['ext-pdo_mysql'] = '*';
        }

        $require['ext-mbstring'] = '*';
        
        if ($hasAuth) {
            $require['julienlinard/auth-php'] = '^1.3';
        }

        if ($hasApi) {
            $require['julienlinard/php-api'] = '^1.3';
        }

        if ($hasVision) {
            $require['julienlinard/php-vision'] = '^1.0';
        }
        
        // Normaliser le nom du projet (minuscules, remplacer espaces et caractères spéciaux par des tirets)
        $normalizedName = strtolower(preg_replace('/[^a-z0-9]+/', '-', $projectName));
        $normalizedName = trim($normalizedName, '-');
        
        $json = [
            'name' => 'app/' . $normalizedName,
            'description' => 'PHP application built with JulienLinard PHP Framework',
            'type' => 'project',
            'license' => 'MIT',
            'require' => $require,
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/'
                ]
            ],
            'minimum-stability' => 'stable',
            'prefer-stable' => true
        ];

        if ($hasVision) {
            $json['repositories'] = [
                [
                    'type' => 'vcs',
                    'url' => 'https://github.com/julien-lin/php-vision',
                ],
            ];
        }
        
        // Ajouter les scripts Composer pour doctrine-php si installé
        if ($hasDoctrine) {
            $json['scripts'] = [
                'doctrine:migrate' => 'vendor/bin/doctrine-migrate migrate',
                'doctrine:generate' => 'vendor/bin/doctrine-migrate generate',
                'doctrine:rollback' => 'vendor/bin/doctrine-migrate rollback',
                'doctrine:create' => 'vendor/bin/doctrine-migrate create',
                'doctrine:drop' => 'vendor/bin/doctrine-migrate drop',
                'doctrine:status' => 'vendor/bin/doctrine-migrate status'
            ];
        }
        
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
/public/uploads/*
!/public/uploads/.gitkeep
GITIGNORE;
        
        file_put_contents($wwwDir . '/.gitignore', $content);
    }
    
    private static function createHomeView(string $homeDir, bool $useVision = false): void
    {
        if ($useVision) {
            $content = <<<'VISION'
<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto bg-white rounded-lg shadow-lg p-8">
        <h1 class="text-4xl font-bold text-gray-800 mb-4">{{ title }}</h1>
        <p class="text-xl text-gray-600 mb-6">{{ message }}</p>
        <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
            <p class="text-blue-700"><strong>🎉 Congratulations!</strong> Your PHP application is running successfully.</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-gray-50 p-4 rounded">
                <h2 class="font-semibold text-gray-800 mb-2">📦 Installed Packages</h2>
                <ul class="text-sm text-gray-600 space-y-1">
                    <li>✅ Core PHP Framework</li>
                    <li>✅ PHP Router</li>
                    <li>✅ PHP Vision</li>
                </ul>
            </div>
            <div class="bg-gray-50 p-4 rounded">
                <h2 class="font-semibold text-gray-800 mb-2">🚀 Next Steps</h2>
                <ul class="text-sm text-gray-600 space-y-1">
                    <li>Create your controllers</li>
                    <li>Add your Vision templates</li>
                    <li>Configure your database</li>
                </ul>
            </div>
        </div>
    </div>
</div>
VISION;

            self::writeGeneratedFile($homeDir . '/index.html.vis', $content);
            return;
        }

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
        
        self::writeGeneratedFile($homeDir . '/index.html.php', $content);
    }
    
    private static function setupLocal(
        bool $installDoctrine,
        bool $installAuth,
        bool $installApi = false,
        bool $installVision = false,
        bool $installSecure = false
    ): void
    {
        echo "\n💻 Configuration locale...\n";
        $baseDir = self::getProjectRoot();
        self::createLocalStructure($baseDir, $installDoctrine, $installAuth, $installApi, $installVision, $installSecure);
        echo "✅ Configuration locale prête.\n";
    }
    
    private static function createLocalStructure(
        string $baseDir,
        bool $installDoctrine,
        bool $installAuth,
        bool $installApi = false,
        bool $installVision = false,
        bool $installSecure = false
    ): void
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
        self::createHeaderTemplate($templatesDir, $installVision);
        self::createFooterTemplate($templatesDir);
        self::createHomeView($homeDir, $installVision);
        self::createLocalDirectories($baseDir);
        self::createConfigDatabase($baseDir, $installDoctrine);
        if ($installAuth) {
            self::createAuthFiles($baseDir);
        }
        if ($installApi) {
            self::createApiFiles($baseDir);
        }
        self::createLocalEnvFiles($baseDir, $installDoctrine, $installApi);
        self::createBootstrapServices($baseDir, $installDoctrine || $installAuth || $installApi);
        self::createWwwGitignore($baseDir);
        
        self::createPublicIndex($publicDir, $installDoctrine, $installAuth, $installApi, $installSecure);
        
        echo "✅ Structure locale créée.\n";
    }

    private static function createAuthFiles(string $baseDir): void
    {
        $entityDir = $baseDir . '/src/Entity';
        $migrationDir = $baseDir . '/migrations';

        if (!is_dir($entityDir) && !mkdir($entityDir, 0755, true) && !is_dir($entityDir)) {
            throw new \RuntimeException("Impossible de créer {$entityDir}.");
        }
        if (!is_dir($migrationDir) && !mkdir($migrationDir, 0755, true) && !is_dir($migrationDir)) {
            throw new \RuntimeException("Impossible de créer {$migrationDir}.");
        }

        $userEntity = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Entity;

use JulienLinard\Auth\Models\UserInterface;
use JulienLinard\Doctrine\Mapping\Column;
use JulienLinard\Doctrine\Mapping\Entity;
use JulienLinard\Doctrine\Mapping\Id;
use JulienLinard\Doctrine\Mapping\Index;

#[Entity(table: 'users')]
class User implements UserInterface
{
    #[Id]
    #[Column(type: 'integer', autoIncrement: true)]
    private ?int $id = null;

    #[Column(type: 'string', length: 255)]
    #[Index(name: 'idx_users_email', unique: true)]
    private string $email;

    #[Column(type: 'string', length: 255)]
    private string $password;

    #[Column(type: 'string', length: 255, default: 'user')]
    private string $roles = 'user';

    #[Column(type: 'string', length: 1000, nullable: true)]
    private ?string $permissions = null;

    #[Column(type: 'datetime', nullable: true, name: 'created_at')]
    private ?\DateTime $createdAt = null;

    public function __construct(?string $email = null, ?string $password = null)
    {
        // Doctrine hydrate les entités via un constructeur sans argument.
        $this->email = $email ?? '';
        $this->password = $password ?? '';
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function getAuthIdentifier(): int|string
    {
        if ($this->id === null) {
            throw new \LogicException('Un utilisateur doit être persisté avant d’être authentifié.');
        }

        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return $this->password;
    }

    public function getAuthRoles(): array|string
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->roles))));
    }

    public function getAuthPermissions(): array
    {
        if ($this->permissions === null || $this->permissions === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $this->permissions))));
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, (array) $this->getAuthRoles(), true);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->getAuthPermissions(), true);
    }
}
PHP;

        self::writeGeneratedFile($entityDir . '/User.php', $userEntity);

        $usersMigration = <<<'SQL'
-- Migration initiale du profil Auth.
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(255) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `roles` VARCHAR(255) NOT NULL DEFAULT 'user',
    `permissions` VARCHAR(1000) NULL,
    `created_at` DATETIME NULL,
    UNIQUE KEY `idx_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        self::writeGeneratedFile($migrationDir . '/20261005_create_users.sql', $usersMigration);

        $rememberTokensMigration = <<<'SQL'
-- Migration nécessaire à la fonctionnalité "Remember Me".
CREATE TABLE IF NOT EXISTS `remember_tokens` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token` VARCHAR(255) NOT NULL UNIQUE,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_remember_user_id` (`user_id`),
    INDEX `idx_remember_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        self::writeGeneratedFile($migrationDir . '/20261005_create_remember_tokens.sql', $rememberTokensMigration);
    }

    private static function writeGeneratedFile(string $path, string $content): void
    {
        if (file_exists($path)) {
            return;
        }

        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Impossible d'écrire le fichier généré {$path}.");
        }
    }

    /**
     * Prépare la configuration locale minimale. Le profil de base doit pouvoir
     * démarrer sans base de données, tandis que le profil Doctrine reçoit des
     * variables DB explicites à compléter par le développeur.
     */
    private static function createLocalEnvFiles(string $baseDir, bool $hasDoctrine, bool $hasApi = false): void
    {
        $envExample = <<<'ENV'
APP_NAME=My PHP Application
APP_ENV=local
APP_DEBUG=1
APP_LOCALE=fr
APP_SECRET=
ENV;

        $env = "APP_NAME=My PHP Application\n";
        $env .= "APP_ENV=local\n";
        $env .= "APP_DEBUG=1\n";
        $env .= "APP_LOCALE=fr\n";
        $env .= 'APP_SECRET=' . bin2hex(random_bytes(32)) . "\n";

        if ($hasDoctrine) {
            $envExample .= <<<'ENV'

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=app_db
DB_USER=app_user
DB_PASS=change-me
ENV;

            $env .= "\nDB_HOST=127.0.0.1\n";
            $env .= "DB_PORT=3306\n";
            $env .= "DB_NAME=app_db\n";
            $env .= "DB_USER=app_user\n";
            $env .= 'DB_PASS=' . bin2hex(random_bytes(16)) . "\n";
        }

        if ($hasApi) {
            $envExample .= <<<'ENV'

# Origines CORS autorisées, séparées par des virgules.
# Laisser vide pour désactiver les requêtes cross-origin.
API_CORS_ORIGINS=
ENV;

            $env .= "\nAPI_CORS_ORIGINS=\n";
        }

        $envPath = $baseDir . '/.env';
        if (!file_exists($envPath)) {
            if (file_put_contents($envPath, $env) === false) {
                throw new \RuntimeException("Impossible d'écrire {$envPath}.");
            }
        }

        $envExamplePath = $baseDir . '/.env.example';
        $existingExample = is_file($envExamplePath) ? file_get_contents($envExamplePath) : false;
        $isSourceDockerExample = is_string($existingExample)
            && str_contains($existingExample, '# Configuration Docker');

        if (!is_file($envExamplePath) || $isSourceDockerExample) {
            if (file_put_contents($envExamplePath, $envExample . "\n") === false) {
                throw new \RuntimeException("Impossible d'écrire {$envExamplePath}.");
            }
        }
    }
    
    private static function createLocalDirectories(string $baseDir): void
    {
        $publicDir = $baseDir . '/public';
        $directories = [
            $baseDir . '/src/Controller',
            $baseDir . '/src/Entity',
            $baseDir . '/src/Middleware',
            $baseDir . '/src/Repository',
            $baseDir . '/src/Service',
            $baseDir . '/storage/logs',
            $baseDir . '/migrations',
            $publicDir . '/uploads',
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
        file_put_contents($publicDir . '/uploads/.gitkeep', '');
        
        // Fixer les permissions pour Linux (après création de tous les dossiers)
        self::fixPermissions($baseDir, false);
    }
    
    private static function configureEnv(bool $hasDatabase, bool $hasApi = false): void
    {
        echo "\n⚙️  Configuration de l'environnement (.env)...\n";
        
        $envData = [];
        
        $envData['APACHE_CONTAINER'] = self::askInput('Nom du container Apache', 'apache_app');
        $envData['APACHE_PORT'] = self::askInput('Port Apache', '80');
        if ($hasDatabase) {
            $envData['MARIADB_CONTAINER'] = self::askInput('Nom du container MariaDB', 'mariadb_app');
            $envData['MARIADB_PORT'] = self::askInput('Port MariaDB', '3306');
            $envData['MYSQL_ROOT_PASSWORD'] = self::askInput(
                'Mot de passe root MariaDB',
                bin2hex(random_bytes(16))
            );
            $envData['MYSQL_DATABASE'] = self::askInput('Nom de la base de données', 'app_db');
            $envData['MYSQL_USER'] = self::askInput('Utilisateur MariaDB', 'app_user');
            $envData['MYSQL_PASSWORD'] = self::askInput(
                'Mot de passe utilisateur MariaDB',
                bin2hex(random_bytes(16))
            );
        }
        $envData['PHP_ERROR_REPORTING'] = self::askInput('PHP Error Reporting (E_ALL)', 'E_ALL');
        $envData['PHP_DISPLAY_ERRORS'] = self::askInput('PHP Display Errors (On/Off)', 'Off');
        
        // Stocker les noms de conteneurs pour les utiliser dans docker-compose.yml
        self::$containerNames = ['apache' => $envData['APACHE_CONTAINER']];
        if ($hasDatabase) {
            self::$containerNames['mariadb'] = $envData['MARIADB_CONTAINER'];
        }
        
        self::createEnvFile($envData, $hasDatabase, $hasApi);
        
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
    
    private static function createEnvFile(array $data, bool $hasDatabase, bool $hasApi = false): void
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
        if ($hasDatabase) {
            // Le conteneur applicatif utilise les mêmes noms DB_* que l'installation locale.
            // MARIADB_PORT reste un port hôte et ne doit pas être réutilisé dans le réseau Docker.
            $wwwContent .= "DB_HOST={$data['MARIADB_CONTAINER']}\n";
            $wwwContent .= "DB_PORT=3306\n";
            $wwwContent .= "DB_NAME={$data['MYSQL_DATABASE']}\n";
            $wwwContent .= "DB_USER={$data['MYSQL_USER']}\n";
            $wwwContent .= "DB_PASS={$data['MYSQL_PASSWORD']}\n";
        }
        $wwwContent .= "PHP_ERROR_REPORTING={$data['PHP_ERROR_REPORTING']}\n";
        $wwwContent .= "PHP_DISPLAY_ERRORS={$data['PHP_DISPLAY_ERRORS']}\n";
        $wwwContent .= "\n";
        $wwwContent .= "# Configuration Application\n";
        $wwwContent .= "APP_SECRET=" . bin2hex(random_bytes(32)) . "\n";
        $wwwContent .= "APP_DEBUG=1\n";
        $wwwContent .= "APP_LOCALE=fr\n";
        if ($hasApi) {
            $wwwContent .= "API_CORS_ORIGINS=\n";
        }
        
        // Créer le dossier www/ s'il n'existe pas
        $wwwDir = dirname($wwwEnvPath);
        if (!is_dir($wwwDir)) {
            mkdir($wwwDir, 0755, true);
        }
        
        file_put_contents($wwwEnvPath, $wwwContent);
        
        // Créer le fichier .env.example
        self::createEnvExample($baseDir, $wwwDir, $hasDatabase, $hasApi);
    }
    
    private static function createEnvExample(string $baseDir, string $wwwDir, bool $hasDatabase, bool $hasApi = false): void
    {
        $envExamplePath = $baseDir . '/.env.example';
        $wwwEnvExamplePath = $wwwDir . '/.env.example';

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
PHP_ERROR_REPORTING=E_ALL
PHP_DISPLAY_ERRORS=Off
ENV;

        if ($hasDatabase) {
            $rootContent .= <<<'ENV'

MARIADB_CONTAINER=mariadb_app
MARIADB_PORT=3306
MYSQL_ROOT_PASSWORD=change-me
MYSQL_DATABASE=app_db
MYSQL_USER=app_user
MYSQL_PASSWORD=change-me
ENV;
        }

        file_put_contents($envExamplePath, $rootContent);

        $wwwContent = <<<'ENV'
# ============================================
# CONFIGURATION APPLICATION PHP
# ============================================
# Ce fichier configure l'application PHP qui tourne DANS le container
#
# IMPORTANT : Les ports ici sont les ports INTERNES du réseau Docker
#
# Copiez ce fichier en .env et modifiez les valeurs selon vos besoins

PHP_ERROR_REPORTING=E_ALL
PHP_DISPLAY_ERRORS=Off

ENV;

        if ($hasDatabase) {
            $wwwContent .= <<<'ENV'
# ============================================
# Configuration Base de données
# ============================================
# Host = nom du service Docker pour la connexion interne
DB_HOST=mariadb_app
DB_PORT=3306
DB_NAME=app_db
DB_USER=app_user
DB_PASS=change-me

ENV;
        }

        if ($hasApi) {
            $wwwContent .= <<<'ENV'
# ============================================
# Configuration CORS API
# ============================================
# Origines autorisées, séparées par des virgules.
# Laisser vide pour désactiver les requêtes cross-origin.
API_CORS_ORIGINS=

ENV;
        }

        $wwwContent .= <<<'ENV'
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

        $productionWwwContent = str_replace(
            ["# Configuration Application\n", "APP_DEBUG=1"],
            ["# Configuration Application\nAPP_ENV=production\n", "APP_DEBUG=0"],
            $wwwContent
        );
        file_put_contents($wwwDir . '/.env.production.example', $productionWwwContent);
    }
    
    private static function createDockerFiles(string $baseDir, bool $hasDatabase): void
    {
        self::createDockerCompose($baseDir, $hasDatabase);
        self::createDockerComposeProduction($baseDir, $hasDatabase);
        self::createDockerfile($baseDir);
        self::createProductionDockerfile($baseDir);
        self::createCustomPhpIni($baseDir);
        self::createProductionPhpIni($baseDir);
        self::createAliases($baseDir);
        self::createDockerignore($baseDir);
    }
    
    private static function createDockerCompose(string $baseDir, bool $hasDatabase): void
    {
        $apacheService = self::$containerNames['apache'] ?? 'apache_app';
        $mariadbService = self::$containerNames['mariadb'] ?? 'mariadb_app';

        $apacheService = preg_replace('/[^a-z0-9_-]/', '_', strtolower($apacheService));
        $mariadbService = preg_replace('/[^a-z0-9_-]/', '_', strtolower($mariadbService));

        $dependsOn = '';
        $databaseService = '';
        $volumes = '';

        if ($hasDatabase) {
            $dependsOn = <<<YAML
    depends_on:
      {$mariadbService}:
        condition: service_healthy
YAML;

            $databaseService = <<<YAML

  {$mariadbService}:
    image: mariadb:11.3
    container_name: \${MARIADB_CONTAINER:-{$mariadbService}}
    restart: unless-stopped
    ports:
      - "127.0.0.1:\${MARIADB_PORT:-3306}:3306"
    environment:
      - MYSQL_ROOT_PASSWORD=\${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD doit être défini}
      - MYSQL_DATABASE=\${MYSQL_DATABASE:?MYSQL_DATABASE doit être défini}
      - MYSQL_USER=\${MYSQL_USER:?MYSQL_USER doit être défini}
      - MYSQL_PASSWORD=\${MYSQL_PASSWORD:?MYSQL_PASSWORD doit être défini}
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
YAML;

            $volumes = <<<'YAML'

volumes:
  mysql:
YAML;
        }

        $content = <<<YAML
services:
  {$apacheService}:
    build: apache
    container_name: \${APACHE_CONTAINER:-{$apacheService}}
    restart: unless-stopped
    ports:
      - "\${APACHE_PORT:-80}:80"
    volumes:
      - ./www:/var/www/html
      - ./apache/custom-php.ini:/usr/local/etc/php/conf.d/custom-php.ini
    environment:
      - PHP_ERROR_REPORTING=\${PHP_ERROR_REPORTING:-E_ALL}
      - PHP_DISPLAY_ERRORS=\${PHP_DISPLAY_ERRORS:-Off}
    networks:
      - app_network
{$dependsOn}
    healthcheck:
      test: ["CMD", "wget", "--quiet", "--tries=1", "--spider", "http://localhost/health"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 40s
    mem_limit: 512m
    mem_reservation: 256m
    cpus: 2.0
{$databaseService}

networks:
  app_network:
    driver: bridge
{$volumes}
YAML;

        file_put_contents($baseDir . '/docker-compose.yml', $content);
    }

    private static function createDockerComposeProduction(string $baseDir, bool $hasDatabase): void
    {
        $apacheService = self::$containerNames['apache'] ?? 'apache_app';
        $mariadbService = self::$containerNames['mariadb'] ?? 'mariadb_app';

        $apacheService = preg_replace('/[^a-z0-9_-]/', '_', strtolower($apacheService));
        $mariadbService = preg_replace('/[^a-z0-9_-]/', '_', strtolower($mariadbService));

        $dependsOn = '';
        $databaseService = '';
        $volumes = '';

        if ($hasDatabase) {
            $dependsOn = <<<YAML
    depends_on:
      {$mariadbService}:
        condition: service_healthy
YAML;

            $databaseService = <<<YAML

  {$mariadbService}:
    image: mariadb:11.3
    container_name: \${MARIADB_CONTAINER:-{$mariadbService}}
    restart: unless-stopped
    environment:
      - MYSQL_ROOT_PASSWORD=\${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD doit être défini}
      - MYSQL_DATABASE=\${MYSQL_DATABASE:?MYSQL_DATABASE doit être défini}
      - MYSQL_USER=\${MYSQL_USER:?MYSQL_USER doit être défini}
      - MYSQL_PASSWORD=\${MYSQL_PASSWORD:?MYSQL_PASSWORD doit être défini}
    volumes:
      - mysql:/var/lib/mysql
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
YAML;

            $volumes = <<<'YAML'

volumes:
  mysql:
YAML;
        }

        $content = <<<YAML
services:
  {$apacheService}:
    build:
      context: .
      dockerfile: apache/Dockerfile.prod
    container_name: \${APACHE_CONTAINER:-{$apacheService}}
    restart: unless-stopped
    ports:
      - "\${APACHE_PORT:-80}:80"
    env_file:
      - ./www/.env
    environment:
      APP_ENV: production
      APP_DEBUG: "0"
      PHP_ERROR_REPORTING: \${PHP_ERROR_REPORTING:-E_ALL & ~E_DEPRECATED & ~E_STRICT}
      PHP_DISPLAY_ERRORS: \${PHP_DISPLAY_ERRORS:-Off}
    volumes:
      - ./www/storage:/var/www/html/storage
      - ./www/public/uploads:/var/www/html/public/uploads
      - ./apache/custom-php-prod.ini:/usr/local/etc/php/conf.d/custom-php.ini:ro
    networks:
      - app_network
{$dependsOn}
    healthcheck:
      test: ["CMD", "wget", "--quiet", "--tries=1", "--spider", "http://localhost/health"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 40s
    mem_limit: 512m
    mem_reservation: 256m
    cpus: 2.0
{$databaseService}

networks:
  app_network:
    driver: bridge
{$volumes}
YAML;

        file_put_contents($baseDir . '/docker-compose.prod.yml', $content);
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
  && docker-php-ext-install -j$(nproc) gd intl mysqli opcache pdo pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf \
  && echo "ServerName localhost\n\
<Directory /var/www/html/public>\n\
  AllowOverride All\n\
  Require all granted\n\
  </Directory>" >> /etc/apache2/apache2.conf \
  && a2enmod rewrite

COPY custom-php.ini /usr/local/etc/php/conf.d/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
DOCKERFILE;
        
        file_put_contents($apacheDir . '/Dockerfile', $content);
    }

    private static function createProductionDockerfile(string $baseDir): void
    {
        $apacheDir = $baseDir . '/apache';
        if (!is_dir($apacheDir)) {
            mkdir($apacheDir, 0755, true);
        }

        $content = <<<'DOCKERFILE'
FROM composer:2 AS dependencies

WORKDIR /app
COPY www/composer.json www/composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
  wget \
  libpng-dev \
  libjpeg-dev \
  libfreetype6-dev \
  libicu-dev \
  && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j$(nproc) gd intl mysqli opcache pdo pdo_mysql

RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf \
  && echo "ServerName localhost\n\
<Directory /var/www/html/public>\n\
  AllowOverride All\n\
  Require all granted\n\
  </Directory>" >> /etc/apache2/apache2.conf \
  && a2enmod rewrite

COPY www/ /var/www/html/
COPY --from=dependencies /app/vendor /var/www/html/vendor
COPY apache/custom-php-prod.ini /usr/local/etc/php/conf.d/custom-php.ini

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
DOCKERFILE;

        file_put_contents($apacheDir . '/Dockerfile.prod', $content);
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

date.timezone = Europe/Paris
INI;
        
        file_put_contents($apacheDir . '/custom-php.ini', $content);
    }

    private static function createProductionPhpIni(string $baseDir): void
    {
        $apacheDir = $baseDir . '/apache';
        if (!is_dir($apacheDir)) {
            mkdir($apacheDir, 0755, true);
        }

        $content = <<<'INI'
[PHP]
display_errors = Off
display_startup_errors = Off
log_errors = On
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
expose_php = Off
html_errors = Off

upload_max_filesize = 20M
post_max_size = 20M
memory_limit = 256M
max_execution_time = 60
max_input_time = 60
date.timezone = Europe/Paris

opcache.enable = 1
opcache.enable_cli = 0
opcache.validate_timestamps = 0
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
INI;

        file_put_contents($apacheDir . '/custom-php-prod.ini', $content);
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
        
        self::writeGeneratedFile($publicDir . '/.htaccess', $content);
    }
    
    private static function createPublicIndex(
        string $publicDir,
        bool $hasDoctrine,
        bool $hasAuth,
        bool $hasApi = false,
        bool $hasSecure = false
    ): void
    {
        $wwwDir = dirname($publicDir);
        $controllerDir = $wwwDir . '/src/Controller';
        if (!is_dir($controllerDir)) {
            mkdir($controllerDir, 0755, true);
        }
        
        $indexContent = self::generateIndexContent($hasDoctrine, $hasAuth, $hasApi, $hasSecure);
        
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

    #[Route(path: '/health', methods: ['GET'], name: 'health')]
    public function health(): Response
    {
        return $this->json([
            'status' => 'ok',
            'framework' => 'php-skeleton'
        ]);
    }
}
PHP;
        
        file_put_contents($publicDir . '/index.php', $indexContent);
        file_put_contents($controllerDir . '/HomeController.php', $controllerContent);

        if ($hasAuth) {
            self::createAuthController($controllerDir);
        }
    }

    private static function createApiFiles(string $baseDir): void
    {
        $entityDir = $baseDir . '/src/Entity';
        $controllerDir = $baseDir . '/src/Controller';
        $migrationDir = $baseDir . '/migrations';

        foreach ([$entityDir, $controllerDir, $migrationDir] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException("Impossible de créer {$directory}.");
            }
        }

        $productEntity = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Entity;

use JulienLinard\Api\Annotation\ApiProperty;
use JulienLinard\Api\Annotation\ApiResource;
use JulienLinard\Doctrine\Mapping\Column;
use JulienLinard\Doctrine\Mapping\Entity;
use JulienLinard\Doctrine\Mapping\Id;

#[ApiResource(
    operations: ['GET', 'POST', 'PUT', 'DELETE'],
    routePrefix: '/api',
    shortName: 'products'
)]
#[Entity(table: 'products')]
final class Product
{
    #[Id]
    #[Column(type: 'integer', autoIncrement: true)]
    #[ApiProperty(groups: ['read'])]
    public ?int $id = null;

    #[Column(type: 'string', length: 255)]
    #[ApiProperty(groups: ['read', 'write'], required: true)]
    public string $name = '';

    #[Column(type: 'float')]
    #[ApiProperty(groups: ['read', 'write'], required: true)]
    public float $price = 0.0;

    public function __construct(array $data = [])
    {
        foreach ($data as $property => $value) {
            if (property_exists($this, $property)) {
                $this->{$property} = $value;
            }
        }
    }
}
PHP;

        self::writeGeneratedFile($entityDir . '/Product.php', $productEntity);

        $productController = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use JulienLinard\Api\Controller\ApiController;
use JulienLinard\Api\Serializer\JsonSerializer;
use JulienLinard\Core\Application;
use JulienLinard\Doctrine\EntityManager;
use JulienLinard\Router\Attributes\Route;
use JulienLinard\Router\Request;
use JulienLinard\Router\Response;

final class ProductController extends ApiController
{
    public function __construct()
    {
        parent::__construct(Product::class, new JsonSerializer());
    }

    #[Route(path: '/api/products', methods: ['GET'], name: 'api.products.index')]
    public function index(Request|array $requestOrParams = []): Response
    {
        try {
            return parent::index($requestOrParams);
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception, '/api/products');
        }
    }

    #[Route(path: '/api/products/{id}', methods: ['GET'], name: 'api.products.show', constraints: ['id' => '\\d+'])]
    public function show(Request|int|string $requestOrId): Response
    {
        try {
            return parent::show($requestOrId);
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception, '/api/products');
        }
    }

    #[Route(path: '/api/products', methods: ['POST'], name: 'api.products.create')]
    public function create(Request|array $requestOrData): Response
    {
        try {
            return parent::create($requestOrData);
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception, '/api/products');
        }
    }

    #[Route(path: '/api/products/{id}', methods: ['PUT'], name: 'api.products.update', constraints: ['id' => '\\d+'])]
    public function update(Request|int|string $requestOrId, ?array $data = null): Response
    {
        try {
            return parent::update($requestOrId, $data);
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception, '/api/products');
        }
    }

    #[Route(path: '/api/products/{id}', methods: ['DELETE'], name: 'api.products.delete', constraints: ['id' => '\\d+'])]
    public function delete(Request|int|string $requestOrId): Response
    {
        try {
            return parent::delete($requestOrId);
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception, '/api/products');
        }
    }

    protected function getAll(array $queryParams = []): array
    {
        return $this->entityManager()
            ->getRepository(Product::class)
            ->findAll();
    }

    protected function getOne(int|string $id): ?object
    {
        return $this->entityManager()
            ->getRepository(Product::class)
            ->find($id);
    }

    protected function save(object $entity): void
    {
        $entityManager = $this->entityManager();
        $entityManager->persist($entity);
        $entityManager->flush();
    }

    protected function remove(object $entity): void
    {
        $entityManager = $this->entityManager();
        $entityManager->remove($entity);
        $entityManager->flush();
    }

    private function entityManager(): EntityManager
    {
        return Application::getInstanceOrFail()
            ->getContainer()
            ->make(EntityManager::class);
    }
}
PHP;

        self::writeGeneratedFile($controllerDir . '/ProductController.php', $productController);

        $migration = <<<'SQL'
-- Migration initiale du profil API.
CREATE TABLE IF NOT EXISTS `products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `price` DECIMAL(12, 2) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        self::writeGeneratedFile($migrationDir . '/20261005_create_products.sql', $migration);
    }

    private static function createAuthController(string $controllerDir): void
    {
        $content = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controller;

use JulienLinard\Auth\AuthManager;
use JulienLinard\Auth\Middleware\AuthMiddleware;
use JulienLinard\Core\Middleware\CsrfMiddleware;
use JulienLinard\Router\Attributes\Route;
use JulienLinard\Router\Request;
use JulienLinard\Router\Response;

/**
 * Exemple minimal d'authentification par session.
 *
 * Les utilisateurs doivent être créés après exécution des migrations.
 */
final class AuthController
{
    public function __construct(private AuthManager $auth)
    {
    }

    #[Route(path: '/login', methods: ['GET'], name: 'auth.login.form')]
    public function loginForm(): Response
    {
        $csrfField = CsrfMiddleware::field();
        $html = <<<HTML
<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>Connexion</title></head>
<body>
    <main>
        <h1>Connexion</h1>
        <form method="post" action="/login">
            {$csrfField}
            <label>Email <input type="email" name="email" required></label>
            <label>Mot de passe <input type="password" name="password" required></label>
            <button type="submit">Se connecter</button>
        </form>
    </main>
</body>
</html>
HTML;

        return new Response(200, $html);
    }

    #[Route(path: '/login', methods: ['POST'], name: 'auth.login')]
    public function login(Request $request): Response
    {
        $email = trim((string) $request->getBodyParam('email', ''));
        $password = (string) $request->getBodyParam('password', '');

        if ($email === '' || $password === '') {
            return Response::json([
                'error' => 'invalid_credentials',
                'message' => 'Email et mot de passe requis.'
            ], 422);
        }

        if (!$this->auth->attempt(['email' => $email, 'password' => $password])) {
            return Response::json([
                'error' => 'invalid_credentials',
                'message' => 'Identifiants invalides.'
            ], 401);
        }

        return Response::json(['status' => 'authenticated']);
    }

    #[Route(path: '/logout', methods: ['POST'], name: 'auth.logout')]
    public function logout(): Response
    {
        $this->auth->logout();

        return Response::json(['status' => 'logged_out']);
    }

    #[Route(
        path: '/account',
        methods: ['GET'],
        name: 'auth.account',
        middleware: [new AuthMiddleware()]
    )]
    public function account(): Response
    {
        $user = $this->auth->user();

        return Response::json([
            'id' => $user?->getAuthIdentifier(),
            'email' => $user !== null && method_exists($user, 'getEmail') ? $user->getEmail() : null,
            'roles' => $user?->getAuthRoles(),
        ]);
    }
}
PHP;

        self::writeGeneratedFile($controllerDir . '/AuthController.php', $content);
    }
    
    private static function generateIndexContent(
        bool $hasDoctrine,
        bool $hasAuth,
        bool $hasApi = false,
        bool $hasSecure = false
    ): string
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

require_once dirname(__DIR__) . '/vendor/autoload.php';

use JulienLinard\Core\Application;
use JulienLinard\Core\Middleware\CsrfMiddleware;
use JulienLinard\Core\Form\Validator as CoreValidator;
use JulienLinard\Core\View\View;
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

        if ($hasApi) {
            $content .= "\nuse App\Controller\ProductController;";
            $content .= "\nuse JulienLinard\\Core\\Middleware\\CorsMiddleware;";
        }
        
        if ($hasApi || $hasSecure) {
            $content .= <<<'PHP'

use JulienLinard\Core\Middleware\RateLimitMiddleware;
use JulienLinard\Core\Middleware\RequestValidationMiddleware;
PHP;
        }

        if ($hasSecure) {
            $content .= <<<'PHP'

use JulienLinard\Core\Middleware\CompressionMiddleware;
use JulienLinard\Core\Middleware\SecurityHeadersMiddleware;
PHP;
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
$app->setViewsPath(dirname(__DIR__) . '/views');
$app->setPartialsPath(dirname(__DIR__) . '/views/_templates');

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
$viewsPath = dirname(__DIR__) . '/views';

// Le cache de vues est activé uniquement hors développement.
$viewCacheDir = dirname(__DIR__) . '/storage/cache/views';
if ($debug) {
    View::configureCache(null);
} else {
    if (!is_dir($viewCacheDir) && !mkdir($viewCacheDir, 0755, true) && !is_dir($viewCacheDir)) {
        throw new \RuntimeException("Impossible de créer le cache des vues: {$viewCacheDir}");
    }
    View::configureCache($viewCacheDir, 3600);
}

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
    try {
        $entityManager = new EntityManager($dbConfig);
        // Établir la connexion ici pour remonter une erreur exploitable
        // avant l'exécution d'une requête métier.
        $entityManager->getConnection()->getPdo();
        return $entityManager;
    } catch (\Throwable $exception) {
        throw new \RuntimeException(
            'Connexion à la base de données impossible. Vérifiez DB_HOST, DB_PORT, DB_NAME, DB_USER et DB_PASS.',
            0,
            $exception
        );
    }
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

// Enregistrer le validateur déjà fourni par core-php.
// Il s'appuie lui-même sur php-validator et évite un binding redondant.
$container->singleton(CoreValidator::class, static fn(): CoreValidator => new CoreValidator());

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
PHP;

        if ($hasApi) {
            $content .= <<<'PHP'

// CORS API : aucune origine n'est autorisée par défaut.
// Configurez API_CORS_ORIGINS dans .env avec une liste explicite.
$router->addMiddleware(new CorsMiddleware(getenv('API_CORS_ORIGINS') ?: ''));
PHP;
        }

        if ($hasApi && !$hasSecure) {
            $content .= <<<'PHP'

// Validation et limitation de débit ciblées sur les routes API.
// La route /health et les routes web ne consomment pas ce quota.
$router->addMiddleware(new RequestValidationMiddleware(10_485_760, ['/api']));
$router->addMiddleware(new RateLimitMiddleware(100, 60, dirname(__DIR__) . '/storage/cache/rate-limit', ['/api']));
PHP;
        }

        $content .= <<<'PHP'

// Ajouter le middleware CSRF globalement pour toutes les requêtes
// CONCEPT PÉDAGOGIQUE : Middleware Global
// Un middleware global s'exécute sur TOUTES les requêtes
// Ici, il génère le token CSRF si nécessaire et le vérifie pour POST/PUT/DELETE
// CONCEPT : CSRF Protection (Cross-Site Request Forgery)
// Protection contre les attaques où un site malveillant fait des requêtes en votre nom
$router->addMiddleware(new CsrfMiddleware());
PHP;

        if ($hasSecure) {
            $content .= <<<'PHP'

// Profil sécurisé : validation, limitation de débit et headers HTTP.
// L'ordre est volontaire : les requêtes sont validées et limitées avant
// l'exécution des contrôleurs, tandis que les headers et la compression sont
// appliqués au corps de réponse complet par le routeur.
$router->addMiddleware(new RequestValidationMiddleware(10_485_760));
$router->addMiddleware(new RateLimitMiddleware(100, 60, dirname(__DIR__) . '/storage/cache/rate-limit'));
$router->addMiddleware(new SecurityHeadersMiddleware([
    'hsts' => getenv('APP_ENV') === 'production' ? 'max-age=31536000; includeSubDomains' : null,
    'permissionsPolicy' => 'geolocation=(), camera=(), microphone=()',
]));
$router->addMiddleware(new CompressionMiddleware([
    'minSize' => 1024,
]));
PHP;
        }

        $content .= <<<'PHP'

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

PHP;

        if ($hasAuth) {
            $content .= '$router->registerRoutes(\\App\\Controller\\AuthController::class);' . "\n";
        }

        if ($hasApi) {
            $content .= '$router->registerRoutes(\\App\\Controller\\ProductController::class);' . "\n";
        }

        $content .= <<<'PHP'
// Démarrer l'application
$app->start();

// Traiter la requête HTTP
$app->handle();
PHP;
        
        return $content;
    }
    
    private static function createHeaderTemplate(string $templatesDir, bool $useVision = false): void
    {
        if ($useVision) {
            $content = <<<'VISION'
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
VISION;
            self::writeGeneratedFile($templatesDir . '/_header.html.php', $content);
            return;
        }

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
        
        self::writeGeneratedFile($templatesDir . '/_header.html.php', $content);
    }
    
    private static function createFooterTemplate(string $templatesDir): void
    {
        $content = <<<'PHP'
</body>
</html>
PHP;
        
        self::writeGeneratedFile($templatesDir . '/_footer.html.php', $content);
    }
    
    private static function createAliases(string $baseDir): void
    {
        // Utiliser les noms de services configurés (qui correspondent aux noms de conteneurs)
        $apacheService = self::$containerNames['apache'] ?? 'apache_app';
        $mariadbService = self::$containerNames['mariadb'] ?? 'mariadb_app';
        
        // Valider que les noms sont valides pour Docker Compose
        $apacheService = preg_replace('/[^a-z0-9_-]/', '_', strtolower($apacheService));
        $mariadbService = preg_replace('/[^a-z0-9_-]/', '_', strtolower($mariadbService));
        
        $content = <<<BASH
if [ -f .env ]; then
  set -a
  source .env 2>/dev/null || {
    export \$(grep -v '^#' .env | grep -v '^\$' | grep -v '^[[:space:]]*\$' | xargs)
  }
  set +a
fi

# IMPORTANT: Les noms de services dans docker-compose.yml correspondent aux noms de conteneurs
# configurés lors de l'installation. Les aliases utilisent ces noms de services.

# Alias utilisant les noms de services (configurés lors de l'installation)
alias ccomposer='docker compose exec {$apacheService} composer'
alias capache='docker compose exec -it {$apacheService} bash'
alias cmariadb='docker compose exec -it {$mariadbService} bash'
alias db-export='docker compose exec {$mariadbService} /docker-entrypoint-initdb.d/backup.sh'
alias db-import='docker compose exec {$mariadbService} /docker-entrypoint-initdb.d/restore.sh'
BASH;
        
        file_put_contents($baseDir . '/aliases.sh', $content);
    }
    
    private static function createDockerignore(string $baseDir): void
    {
        $content = <<<'IGNORE'
vendor/
.env
.env.local
.env.*
**/.env
**/.env.*
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
    
    /**
     * Fixe les permissions des dossiers critiques pour Linux
     * 
     * @param string $baseDir Répertoire de base (www/ pour Docker, racine pour local)
     * @param bool $isDocker True si installation Docker, false si local
     */
    private static function fixPermissions(string $baseDir, bool $isDocker): void
    {
        // Détecter si on est sous Linux
        $isLinux = PHP_OS_FAMILY === 'Linux';
        
        if (!$isLinux) {
            // Sous macOS/Windows, les permissions sont généralement OK
            return;
        }
        
        $publicDir = $isDocker ? $baseDir . '/public' : $baseDir . '/public';
        $storageLogsDir = $baseDir . '/storage/logs';
        $uploadsDir = $publicDir . '/uploads';
        
        // Dossiers critiques qui doivent être accessibles en écriture
        $writableDirs = [
            $storageLogsDir,
            $uploadsDir,
        ];
        
        foreach ($writableDirs as $dir) {
            if (is_dir($dir)) {
                // Fixer les permissions à 755 (rwxr-xr-x)
                @chmod($dir, 0755);
                
                // Si on est dans Docker, essayer de changer le propriétaire en www-data
                // (cela nécessite sudo, donc on essaie seulement)
                if ($isDocker) {
                    // Dans Docker, le serveur web tourne avec www-data
                    // On essaie de changer le propriétaire, mais cela peut échouer sans sudo
                    @chown($dir, 'www-data');
                    @chgrp($dir, 'www-data');
                }
            }
        }
        
        // Créer le script fix-permissions.sh pour pouvoir refixer les permissions plus tard
        self::createFixPermissionsScript($baseDir, $isDocker);
    }
    
    /**
     * Crée un script shell pour fixer les permissions sous Linux
     * 
     * @param string $baseDir Répertoire de base
     * @param bool $isDocker True si installation Docker
     */
    private static function createFixPermissionsScript(string $baseDir, bool $isDocker): void
    {
        $scriptPath = $baseDir . '/fix-permissions.sh';
        
        if ($isDocker) {
            // Script pour Docker
            $content = <<<'BASH'
#!/bin/bash

# ============================================
# SCRIPT DE CORRECTION DES PERMISSIONS (Docker)
# ============================================
# 
# Ce script fixe les permissions des dossiers critiques
# pour que l'application fonctionne correctement sous Linux.
#
# Usage: ./fix-permissions.sh
# Ou depuis le container: docker compose exec apache_app bash fix-permissions.sh

set -e

echo "🔧 Correction des permissions..."

# Dossiers qui doivent être accessibles en écriture
STORAGE_LOGS="storage/logs"
PUBLIC_UPLOADS="public/uploads"

# Créer les dossiers s'ils n'existent pas
mkdir -p "$STORAGE_LOGS"
mkdir -p "$PUBLIC_UPLOADS"

# Fixer les permissions (755 = rwxr-xr-x)
chmod -R 755 "$STORAGE_LOGS"
chmod -R 755 "$PUBLIC_UPLOADS"

# Si on est dans le container Docker, changer le propriétaire en www-data
if [ -n "$DOCKER_CONTAINER" ] || [ -f /.dockerenv ]; then
    echo "🐳 Détection Docker - Changement du propriétaire en www-data..."
    chown -R www-data:www-data "$STORAGE_LOGS" 2>/dev/null || echo "⚠️  Impossible de changer le propriétaire (nécessite sudo)"
    chown -R www-data:www-data "$PUBLIC_UPLOADS" 2>/dev/null || echo "⚠️  Impossible de changer le propriétaire (nécessite sudo)"
else
    # Si on est sur l'hôte Linux, utiliser l'utilisateur actuel
    CURRENT_USER=$(whoami)
    echo "👤 Utilisation de l'utilisateur actuel: $CURRENT_USER"
    chown -R "$CURRENT_USER:$CURRENT_USER" "$STORAGE_LOGS" 2>/dev/null || echo "⚠️  Impossible de changer le propriétaire (nécessite sudo)"
    chown -R "$CURRENT_USER:$CURRENT_USER" "$PUBLIC_UPLOADS" 2>/dev/null || echo "⚠️  Impossible de changer le propriétaire (nécessite sudo)"
fi

echo "✅ Permissions corrigées avec succès!"
echo ""
echo "📝 Dossiers corrigés:"
echo "   - $STORAGE_LOGS (755)"
echo "   - $PUBLIC_UPLOADS (755)"
BASH;
        } else {
            // Script pour installation locale
            $content = <<<'BASH'
#!/bin/bash

# ============================================
# SCRIPT DE CORRECTION DES PERMISSIONS (Local)
# ============================================
# 
# Ce script fixe les permissions des dossiers critiques
# pour que l'application fonctionne correctement sous Linux.
#
# Usage: ./fix-permissions.sh
# Ou avec sudo si nécessaire: sudo ./fix-permissions.sh

set -e

echo "🔧 Correction des permissions..."

# Dossiers qui doivent être accessibles en écriture
STORAGE_LOGS="storage/logs"
PUBLIC_UPLOADS="public/uploads"

# Créer les dossiers s'ils n'existent pas
mkdir -p "$STORAGE_LOGS"
mkdir -p "$PUBLIC_UPLOADS"

# Fixer les permissions (755 = rwxr-xr-x)
chmod -R 755 "$STORAGE_LOGS"
chmod -R 755 "$PUBLIC_UPLOADS"

# Détecter l'utilisateur du serveur web
if command -v apache2 >/dev/null 2>&1 || command -v httpd >/dev/null 2>&1; then
    # Apache détecté
    WEB_USER="www-data"
    if id "$WEB_USER" &>/dev/null; then
        echo "🌐 Détection Apache - Changement du propriétaire en $WEB_USER..."
        sudo chown -R "$WEB_USER:$WEB_USER" "$STORAGE_LOGS" 2>/dev/null || {
            echo "⚠️  Impossible de changer le propriétaire (nécessite sudo)"
            echo "   Exécutez: sudo ./fix-permissions.sh"
        }
        sudo chown -R "$WEB_USER:$WEB_USER" "$PUBLIC_UPLOADS" 2>/dev/null || {
            echo "⚠️  Impossible de changer le propriétaire (nécessite sudo)"
            echo "   Exécutez: sudo ./fix-permissions.sh"
        }
    fi
else
    # Utiliser l'utilisateur actuel
    CURRENT_USER=$(whoami)
    echo "👤 Utilisation de l'utilisateur actuel: $CURRENT_USER"
    chown -R "$CURRENT_USER:$CURRENT_USER" "$STORAGE_LOGS" 2>/dev/null || echo "⚠️  Impossible de changer le propriétaire"
    chown -R "$CURRENT_USER:$CURRENT_USER" "$PUBLIC_UPLOADS" 2>/dev/null || echo "⚠️  Impossible de changer le propriétaire"
fi

echo "✅ Permissions corrigées avec succès!"
echo ""
echo "📝 Dossiers corrigés:"
echo "   - $STORAGE_LOGS (755)"
echo "   - $PUBLIC_UPLOADS (755)"
BASH;
        }
        
        file_put_contents($scriptPath, $content);
        
        // Rendre le script exécutable
        @chmod($scriptPath, 0755);
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
            echo "   4. (Linux) Fixez les permissions: cd www && ./fix-permissions.sh\n";
            echo "   5. Visitez http://localhost (ou le port configuré)\n";
            echo "   6. Utilisez 'ccomposer' pour les commandes Composer dans Docker\n";
            echo "   7. Production: docker compose -f docker-compose.prod.yml up -d --build\n";
        } else {
            echo "   1. Configurez votre fichier .env si nécessaire\n";
            echo "   2. Installez les dépendances: composer install\n";
            echo "   3. (Linux) Fixez les permissions: ./fix-permissions.sh\n";
            echo "   4. Lancez votre serveur: php -S localhost:8000 -t public\n";
            echo "   5. Visitez http://localhost:8000\n";
        }
        
        echo "\n";
        echo "📚 Documentation:\n";
        echo "   - Router: https://packagist.org/packages/julienlinard/php-router\n";
        echo "   - Core: https://packagist.org/packages/julienlinard/core-php\n";
        echo "\n";
    }
}
