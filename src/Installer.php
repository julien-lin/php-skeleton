<?php

declare(strict_types=1);

namespace Julien;

use Julien\Installer\InstallOptions;
use Julien\Installer\InstallPaths;
use Julien\Installer\ComposerRunner;
use Julien\Installer\NpmRunner;
use Julien\Installer\TemplateRepository;

class Installer
{
    /**
     * Stocke les noms de conteneurs configurés pour les utiliser dans docker-compose.yml
     */
    private static ?array $containerNames = null;
    
    public static function postInstall(): void
    {
        self::displayWelcome();
        self::assertRequiredBinaries();
        $composer = self::createComposerRunner();
        
        $options = InstallOptions::fromChoices(
            self::askQuestion('Voulez-vous utiliser Docker ? (y/N)', false),
            self::askQuestion('Voulez-vous installer Doctrine ? (y/N)', false),
            self::askQuestion('Voulez-vous installer Auth ? (y/N)', false),
            self::askQuestion('Voulez-vous installer le profil API ? (y/N)', false),
            self::askQuestion('Voulez-vous installer le profil Vision ? (y/N)', false),
            self::askQuestion('Voulez-vous activer le profil sécurisé ? (y/N)', false),
            self::askQuestion('Voulez-vous utiliser Tailwind CSS 4 ? (y/N)', false)
        );

        if ($options->useTailwind) {
            self::assertNodeRequiredBinaries();
        }
        
        $baseDir = self::getProjectRoot();

        self::assertInstallTargetIsSkeleton($baseDir, $options->useDocker);

        $stagingDir = self::createInstallationStagingDirectory();
        $paths = new InstallPaths($baseDir, $stagingDir, $options->useDocker);
        try {
            $wwwDir = $paths->applicationRoot();

            if ($options->useDocker) {
                // Configurer l'environnement dans le staging avant de créer Docker.
                self::configureEnv($options->installDoctrine, $options->installApi, $stagingDir);
                self::setupDocker($options->installDoctrine, $options->installAuth, $options->installApi, $options->installVision, $options->installSecure, $stagingDir, $options->useTailwind);
            } else {
                self::setupLocal($options->installDoctrine, $options->installAuth, $options->installApi, $options->installVision, $options->installSecure, $stagingDir, $options->useTailwind);
            }

            self::copyComposerJson($baseDir, $wwwDir, $options->installDoctrine, $options->installAuth, $options->installApi, $options->installVision, $options->installSecure);
            self::validateGeneratedPhpFiles($wwwDir);
            self::validateGeneratedPlaceholders($wwwDir);

            // Le composer.json généré contient déjà le profil choisi : une seule
            // résolution évite les lockfiles intermédiaires et les incohérences.
            self::installDependencies($wwwDir, $composer);

            // Régénérer l'autoloader après la création des fichiers
            self::regenerateAutoloader($wwwDir, $composer);
            self::publishInstallationStaging($paths);
        } catch (\Throwable $exception) {
            self::removeDirectory($paths->stagingRoot);
            throw $exception;
        }

        self::removeDirectory($paths->stagingRoot);
        self::displayCompletion(
            $options->useDocker,
            $options->installDoctrine,
            $options->installAuth,
            $options->installApi,
            $options->installVision,
            $options->installSecure,
            $options->useTailwind
        );
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
        if (self::isNonInteractive()) {
            return $default;
        }

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
    
    private static function installPackage(string $package, string $baseDir, ?ComposerRunner $composer = null): void
    {
        echo "\n📦 Installation de {$package}...\n";

        self::assertPackageName($package);

        $composer ??= self::createComposerRunner();
        [$output, $returnCode] = $composer->run($baseDir, ['require', $package, '--no-interaction', '--prefer-dist']);

        if ($returnCode === 0) {
            echo "✅ {$package} installé avec succès.\n";
        } else {
            throw new \RuntimeException(
                "Échec de l'installation de {$package}:\n" . implode("\n", $output)
            );
        }
    }
    
    private static function installPackageInDocker(string $package, string $wwwDir, ?ComposerRunner $composer = null): void
    {
        echo "\n📦 Installation de {$package} dans www/...\n";

        if (!is_dir($wwwDir)) {
            throw new \RuntimeException("Le répertoire {$wwwDir} n'existe pas.");
        }

        self::assertPackageName($package);

        $composer ??= self::createComposerRunner();
        [$output, $returnCode] = $composer->run($wwwDir, ['require', $package, '--no-interaction', '--prefer-dist']);

        if ($returnCode === 0) {
            echo "✅ {$package} installé avec succès dans www/.\n";
        } else {
            throw new \RuntimeException(
                "Échec de l'installation de {$package} dans www/:\n" . implode("\n", $output)
            );
        }
    }
    
    private static function regenerateAutoloader(string $targetDir, ?ComposerRunner $composer = null): void
    {
        echo "\n🔄 Régénération de l'autoloader...\n";

        $composer ??= self::createComposerRunner();
        [$output, $returnCode] = $composer->run($targetDir, ['dump-autoload', '--no-interaction']);

        if ($returnCode === 0) {
            echo "✅ Autoloader régénéré avec succès.\n";
        } else {
            throw new \RuntimeException(
                "Échec de la régénération de l'autoloader:\n" . implode("\n", $output)
            );
        }
    }

    private static function installDependencies(string $targetDir, ?ComposerRunner $composer = null): void
    {
        echo "\n📦 Installation des dépendances du profil...\n";

        $composer ??= self::createComposerRunner();
        [$output, $returnCode] = $composer->run(
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

    private static function validateGeneratedPhpFiles(string $targetDir): void
    {
        if (!is_dir($targetDir)) {
            throw new \RuntimeException("Répertoire généré introuvable: {$targetDir}");
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($targetDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $path = $file->getPathname();
            $process = proc_open(
                [PHP_BINARY, '-l', $path],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($process)) {
                throw new \RuntimeException("Impossible de vérifier la syntaxe PHP de {$path}.");
            }

            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                throw new \RuntimeException(
                    "Syntaxe PHP invalide dans le fichier généré {$path}:\n" . trim($output)
                );
            }
        }
    }

    private static function validateGeneratedPlaceholders(string $targetDir): void
    {
        if (!is_dir($targetDir)) {
            throw new \RuntimeException("Répertoire généré introuvable: {$targetDir}");
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($targetDir, \FilesystemIterator::SKIP_DOTS)
        );
        $markers = [
            'your-vendor/',
            'your-project',
            '__PLACEHOLDER__',
            'CHANGE_ME',
            'REPLACE_ME',
        ];

        foreach ($files as $file) {
            $pathParts = explode(DIRECTORY_SEPARATOR, $file->getPathname());
            if (
                !$file->isFile()
                || in_array('vendor', $pathParts, true)
                || in_array('node_modules', $pathParts, true)
            ) {
                continue;
            }

            // Les fichiers binaires et les exemples .env peuvent contenir des
            // valeurs pédagogiques ou des octets qui ne sont pas des templates.
            if ($file->getFilename() === '.env.example' || filesize($file->getPathname()) === 0) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false || preg_match('//u', $content) !== 1) {
                continue;
            }

            foreach ($markers as $marker) {
                if (stripos($content, $marker) !== false) {
                    throw new \RuntimeException(
                        "Placeholder non résolu '{$marker}' dans le fichier généré {$file->getPathname()}."
                    );
                }
            }
        }
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
    private static function createComposerRunner(): ComposerRunner
    {
        $composerPath = self::findComposer();
        if ($composerPath === null) {
            throw new \RuntimeException(
                "Composer est requis pour terminer l'installation. " .
                "Installez Composer puis relancez l'installateur."
            );
        }

        return new ComposerRunner(
            $composerPath,
            self::isVerbose(),
            static fn(string $text): string => self::redactSensitiveText($text),
            static function (string $message): void {
                self::verbose($message);
            }
        );
    }

    private static function runComposer(string $workingDirectory, array $arguments): array
    {
        return self::createComposerRunner()->run($workingDirectory, $arguments);
    }

    private static function isVerbose(): bool
    {
        $value = getenv('PHP_SKELETON_VERBOSE');
        if ($value === false) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function verbose(string $message): void
    {
        if (!self::isVerbose()) {
            return;
        }

        echo "[verbose] " . self::redactSensitiveText($message) . "\n";
    }

    private static function redactSensitiveText(string $text): string
    {
        $text = preg_replace_callback(
            '/\b(APP_SECRET|DB_PASS|MYSQL_PASSWORD|MYSQL_ROOT_PASSWORD|PASSWORD|TOKEN|API_KEY)\s*([=:])\s*([^\s,;]+)/i',
            static fn(array $matches): string => $matches[1] . $matches[2] . '[REDACTED]',
            $text
        ) ?? $text;

        return preg_replace_callback(
            '/(--(?:password|token|secret|api-key)(?:=|\s+))([^\s]+)/i',
            static fn(array $matches): string => $matches[1] . '[REDACTED]',
            $text
        ) ?? $text;
    }

    private static function assertPackageName(string $package): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/i', $package)) {
            throw new \RuntimeException("Commande non autorisée: package {$package}");
        }
    }

    private static function validateProjectName(string $projectName): void
    {
        $normalizedName = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $projectName), '-'));
        if ($normalizedName === '' || strlen($normalizedName) > 64) {
            throw new \RuntimeException(
                "Nom de projet invalide: {$projectName}. Utilisez au moins une lettre ou un chiffre."
            );
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

    private static function findNpm(): ?string
    {
        $configuredPath = getenv('NPM_BINARY');
        if ($configuredPath !== false && $configuredPath !== '' && is_executable($configuredPath)) {
            return $configuredPath;
        }

        try {
            $whichNpm = self::safeShellExec('which npm');
            if (!empty($whichNpm) && is_executable($whichNpm)) {
                return $whichNpm;
            }
        } catch (\RuntimeException $e) {
            // Le message détaillé est fourni par assertNodeRequiredBinaries().
        }

        return null;
    }

    private static function createNpmRunner(): NpmRunner
    {
        $npmPath = self::findNpm();
        if ($npmPath === null) {
            throw new \RuntimeException(
                'npm est requis pour installer Tailwind CSS 4. Installez Node.js puis relancez l’installation.'
            );
        }

        return new NpmRunner(
            $npmPath,
            self::isVerbose(),
            static fn(string $text): string => self::redactSensitiveText($text),
            static function (string $message): void {
                self::verbose($message);
            }
        );
    }

    private static function assertRequiredBinaries(): void
    {
        if (self::findComposer() === null) {
            throw new \RuntimeException(
                'Composer est requis avant de commencer la génération. ' .
                'Installez Composer puis relancez l’installation.'
            );
        }
    }

    private static function assertNodeRequiredBinaries(): void
    {
        if (self::findNpm() === null) {
            throw new \RuntimeException(
                'Node.js et npm sont requis pour installer Tailwind CSS 4. ' .
                'Installez Node.js depuis https://nodejs.org puis relancez l’installation.'
            );
        }
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
        bool $installSecure = false,
        ?string $baseDir = null,
        bool $useTailwind = false
    ): void
    {
        echo "\n🐳 Configuration Docker...\n";
        
        $baseDir ??= self::getProjectRoot();
        
        self::createWwwStructure($baseDir, $installDoctrine, $installAuth, $installApi, $installVision, $installSecure, null, $useTailwind);
        self::createDockerFiles($baseDir, $installDoctrine);
        
        echo "✅ Fichiers Docker créés.\n";
    }
    
    private static function getProjectRoot(): string
    {
        return getcwd() ?: dirname(__DIR__, 1);
    }

    private static function createInstallationStagingDirectory(): string
    {
        $stagingDir = sys_get_temp_dir() . '/php-skeleton-install-' . bin2hex(random_bytes(8));
        if (!mkdir($stagingDir, 0755, true) && !is_dir($stagingDir)) {
            throw new \RuntimeException('Impossible de créer le répertoire temporaire de génération.');
        }

        return $stagingDir;
    }

    private static function publishInstallationStaging(InstallPaths $paths): void
    {
        $rollbackDir = self::createInstallationStagingDirectory();
        $backups = [];
        $directoryBackups = [];
        $createdFiles = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($paths->stagingRoot, \FilesystemIterator::SKIP_DOTS)
        );

        try {
            foreach ($files as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $sourcePath = $file->getPathname();
                $relativePath = $paths->relativeStagingPath($sourcePath);

                // Les dépendances Node sont utilisées pour compiler les assets,
                // mais ne doivent jamais être publiées dans le projet généré.
                if (in_array('node_modules', explode(DIRECTORY_SEPARATOR, $relativePath), true)) {
                    continue;
                }

                $targetPath = $paths->targetPath($relativePath);

                // Une configuration locale existante reste prioritaire sur le secret
                // généré dans le staging.
                if (!$paths->useDocker && $relativePath === '.env' && is_file($targetPath)) {
                    continue;
                }

                if (is_file($targetPath) && !isset($backups[$targetPath])) {
                    $backupPath = $rollbackDir . DIRECTORY_SEPARATOR . $relativePath;
                    $backupDirectory = dirname($backupPath);
                    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0755, true) && !is_dir($backupDirectory)) {
                        throw new \RuntimeException("Impossible de créer la sauvegarde de {$relativePath}.");
                    }
                    if (!copy($targetPath, $backupPath)) {
                        throw new \RuntimeException("Impossible de sauvegarder {$relativePath} avant publication.");
                    }
                    $backups[$targetPath] = $backupPath;
                } elseif (!file_exists($targetPath)) {
                    $createdFiles[] = $targetPath;
                }

                $targetDirectory = dirname($targetPath);
                if (file_exists($targetDirectory) && !is_dir($targetDirectory)) {
                    throw new \RuntimeException("Impossible de créer le répertoire {$targetDirectory}.");
                }
                if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0755, true) && !is_dir($targetDirectory)) {
                    throw new \RuntimeException("Impossible de créer le répertoire {$targetDirectory}.");
                }

                if (!copy($sourcePath, $targetPath)) {
                    throw new \RuntimeException("Impossible de publier le fichier généré {$relativePath}.");
                }
            }

            if ($paths->useDocker) {
                foreach (['public', 'src', 'templates', 'config', 'vendor'] as $item) {
                    $path = $paths->targetPath($item);
                    if (!is_dir($path)) {
                        continue;
                    }

                    $backupPath = $rollbackDir . DIRECTORY_SEPARATOR . 'root' . DIRECTORY_SEPARATOR . $item;
                    self::copyDirectoryTree($path, $backupPath);
                    $directoryBackups[$path] = $backupPath;
                }

                self::cleanupRootFiles($paths->projectRoot);
            }
        } catch (\Throwable $exception) {
            foreach (array_reverse($createdFiles) as $createdFile) {
                if (is_file($createdFile)) {
                    unlink($createdFile);
                }
            }
            foreach ($backups as $targetPath => $backupPath) {
                $targetDirectory = dirname($targetPath);
                if (!is_dir($targetDirectory)) {
                    mkdir($targetDirectory, 0755, true);
                }
                copy($backupPath, $targetPath);
            }
            foreach ($directoryBackups as $targetPath => $backupPath) {
                if (is_dir($targetPath)) {
                    self::removeDirectory($targetPath);
                }
                self::copyDirectoryTree($backupPath, $targetPath);
            }
            throw $exception;
        } finally {
            self::removeDirectory($rollbackDir);
        }
    }

    private static function copyDirectoryTree(string $source, string $target): void
    {
        if (!is_dir($source)) {
            throw new \RuntimeException("Répertoire source introuvable: {$source}");
        }
        if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
            throw new \RuntimeException("Impossible de créer la sauvegarde de {$source}.");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = $iterator->getSubPathName();
            $targetPath = $target . DIRECTORY_SEPARATOR . $relativePath;
            if ($item->isDir()) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                    throw new \RuntimeException("Impossible de sauvegarder {$relativePath}.");
                }
                continue;
            }

            $targetDirectory = dirname($targetPath);
            if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0755, true) && !is_dir($targetDirectory)) {
                throw new \RuntimeException("Impossible de sauvegarder {$relativePath}.");
            }
            if (!copy($item->getPathname(), $targetPath)) {
                throw new \RuntimeException("Impossible de sauvegarder {$relativePath}.");
            }
        }
    }
    
    private static function createWwwStructure(
        string $baseDir,
        bool $installDoctrine,
        bool $installAuth,
        bool $installApi = false,
        bool $installVision = false,
        bool $installSecure = false,
        ?TemplateRepository $templates = null,
        bool $useTailwind = false
    ): void
    {
        $templates ??= new TemplateRepository(dirname(__DIR__) . '/templates/installer');
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
        self::createHeaderTemplate($templatesDir, $installVision, $templates, $useTailwind);
        self::createFooterTemplate($templatesDir, $templates);
        self::createHomeView($homeDir, $installVision, $templates, $useTailwind);
        self::createFrontendAssets($wwwDir, $useTailwind);
        self::createWwwDirectories($wwwDir);
        self::createConfigDatabase($wwwDir, $installDoctrine);
        if ($installAuth) {
            self::createAuthFiles($wwwDir);
        }
        if ($installApi) {
            self::createApiFiles($wwwDir);
        }
        self::createBootstrapServices($wwwDir, $installDoctrine || $installAuth || $installApi);
        self::createPublicIndex($publicDir, $installDoctrine, $installAuth, $installApi, $installSecure, $templates);
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
        self::createUploadsHtaccess($publicDir . '/uploads');
        
        // Fixer les permissions pour Linux (après création de tous les dossiers)
        self::fixPermissions($wwwDir, true);
    }

    private static function createFrontendAssets(string $baseDir, bool $useTailwind): void
    {
        $stylesDir = $baseDir . '/assets/styles';
        $publicAssetsDir = $baseDir . '/public/assets';

        $directories = [$publicAssetsDir];
        if ($useTailwind) {
            $directories[] = $stylesDir;
        }

        foreach ($directories as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException("Impossible de créer {$directory}.");
            }
        }

        if ($useTailwind) {
            $sourceCss = <<<'CSS'
@import "tailwindcss";

/* Les templates de l'application contiennent les classes utilisées par Tailwind. */
@source "../../views";
@source "../../src";
@source not "../../public";
CSS;

            self::writeGeneratedFile($stylesDir . '/app.css', $sourceCss . "\n");
            self::writeGeneratedFile($baseDir . '/postcss.config.mjs', <<<'JS'
export default {
  plugins: {
    "@tailwindcss/postcss": {},
  },
};
JS
            . "\n");
            self::writeGeneratedFile($baseDir . '/package.json', self::generateFrontendPackageJson(basename($baseDir)));

            $npm = self::createNpmRunner();
            [$installOutput, $installReturnCode] = $npm->run(
                $baseDir,
                ['install', '--no-audit', '--no-fund', '--no-interaction']
            );
            if ($installReturnCode !== 0) {
                throw new \RuntimeException(
                    "Échec de l'installation des dépendances frontend:\n" . implode("\n", $installOutput)
                );
            }

            [$buildOutput, $buildReturnCode] = $npm->run($baseDir, ['run', 'build']);
            if ($buildReturnCode !== 0) {
                throw new \RuntimeException(
                    "Échec de la compilation Tailwind CSS:\n" . implode("\n", $buildOutput)
                );
            }

            echo "✅ Tailwind CSS 4 installé et compilé.\n";
            return;
        }

        $classicCss = <<<'CSS'
:root {
    color-scheme: light;
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    color: #1f2937;
    background: #f3f4f6;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-width: 320px;
}

.site-body {
    min-height: 100vh;
    background: #f3f4f6;
}

.app-container {
    width: min(100% - 2rem, 64rem);
    margin: 0 auto;
    padding: 2rem 0;
}

.page-card {
    max-width: 56rem;
    margin: 0 auto;
    padding: 2rem;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
    box-shadow: 0 10px 25px rgb(15 23 42 / 0.08);
}

.page-title {
    margin: 0 0 1rem;
    color: #1f2937;
    font-size: clamp(2rem, 5vw, 2.25rem);
    line-height: 1.1;
}

.page-lead {
    margin: 0 0 1.5rem;
    color: #4b5563;
    font-size: 1.25rem;
}

.notice {
    margin-bottom: 1.5rem;
    padding: 1rem;
    color: #1d4ed8;
    background: #eff6ff;
    border-left: 0.25rem solid #3b82f6;
    border-radius: 0.25rem;
}

.cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
    gap: 1rem;
}

.card {
    padding: 1rem;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
}

.card h2 {
    margin: 0 0 0.5rem;
    color: #1f2937;
    font-size: 1rem;
}

.card-list {
    margin: 0;
    padding-left: 1.25rem;
    color: #4b5563;
    font-size: 0.875rem;
    line-height: 1.6;
}

.flash-wrapper {
    position: fixed;
    z-index: 50;
    top: 1rem;
    right: 1rem;
    width: min(calc(100% - 2rem), 28rem);
}

.flash {
    margin-bottom: 0.75rem;
    padding: 1rem;
    border-left: 0.25rem solid;
    border-radius: 0.5rem;
    box-shadow: 0 10px 25px rgb(15 23 42 / 0.12);
}

.flash--success {
    color: #166534;
    background: #f0fdf4;
    border-color: #22c55e;
}

.flash--error {
    color: #991b1b;
    background: #fef2f2;
    border-color: #ef4444;
}
CSS;

        self::writeGeneratedFile($publicAssetsDir . '/app.css', $classicCss . "\n");
        echo "✅ Fichiers CSS classiques créés.\n";
    }

    private static function generateFrontendPackageJson(string $projectName): string
    {
        self::validateProjectName($projectName);
        $normalizedName = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($projectName)), '-');

        $package = [
            'name' => $normalizedName . '-frontend',
            'private' => true,
            'type' => 'module',
            'scripts' => [
                'build' => 'postcss assets/styles/app.css -o public/assets/app.css --env production',
                'watch' => 'postcss assets/styles/app.css -o public/assets/app.css --watch',
            ],
            'devDependencies' => [
                '@tailwindcss/postcss' => '^4.3.0',
                'postcss' => '^8.5.0',
                'postcss-cli' => '^11.0.0',
                'tailwindcss' => '^4.3.0',
            ],
        ];

        return json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
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

    private static function createUploadsHtaccess(string $uploadsDir): void
    {
        $content = <<<'HTACCESS'
# Les uploads ne doivent jamais être interprétés comme du code exécutable.
<FilesMatch "\.(php[0-9]?|phtml|phar|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>
HTACCESS;

        self::writeGeneratedFile($uploadsDir . '/.htaccess', $content);
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
        $targetComposer = $targetDir . '/composer.json';

        if (is_file($targetComposer)) {
            $existing = json_decode((string) file_get_contents($targetComposer), true);
            if (!is_array($existing) || ($existing['name'] ?? null) !== 'julienlinard/php-skeleton') {
                throw new \RuntimeException(
                    "Le fichier {$targetComposer} existe déjà et ne correspond pas au skeleton source."
                );
            }
        }

        $content = self::generateComposerJson(
            basename($baseDir),
            $hasDoctrine,
            $hasAuth,
            $hasApi,
            $hasVision,
            $hasSecure
        );

        if (file_put_contents($targetComposer, $content) === false) {
            throw new \RuntimeException("Impossible d'écrire {$targetComposer}.");
        }
    }

    private static function generateComposerJson(
        string $projectName,
        bool $hasDoctrine,
        bool $hasAuth,
        bool $hasApi = false,
        bool $hasVision = false,
        bool $hasSecure = false
    ): string
    {
        if ($hasAuth || $hasApi) {
            $hasDoctrine = true;
        }

        self::validateProjectName($projectName);

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
        $normalizedName = preg_replace('/[^a-z0-9]+/', '-', strtolower($projectName));
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

        return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    
    private static function createWwwGitignore(string $wwwDir): void
    {
        $content = <<<'GITIGNORE'
/vendor
/node_modules
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
    
    private static function createHomeView(
        string $homeDir,
        bool $useVision = false,
        ?TemplateRepository $templates = null,
        bool $useTailwind = false
    ): void
    {
        $templates ??= new TemplateRepository(dirname(__DIR__) . '/templates/installer');
        if ($useVision) {
            $template = $useTailwind
                ? 'profiles/vision/views/home/index.html.vis'
                : 'profiles/vision/views/home/index.classic.html.vis';
            $target = 'index.html.vis';
        } else {
            $template = $useTailwind
                ? 'profiles/base/views/home/index.html.php'
                : 'profiles/base/views/home/index.classic.html.php';
            $target = 'index.html.php';
        }

        self::writeGeneratedFile($homeDir . '/' . $target, $templates->read($template));
    }
    
    private static function setupLocal(
        bool $installDoctrine,
        bool $installAuth,
        bool $installApi = false,
        bool $installVision = false,
        bool $installSecure = false,
        ?string $baseDir = null,
        bool $useTailwind = false
    ): void
    {
        echo "\n💻 Configuration locale...\n";
        $baseDir ??= self::getProjectRoot();
        self::createLocalStructure($baseDir, $installDoctrine, $installAuth, $installApi, $installVision, $installSecure, null, $useTailwind);
        echo "✅ Configuration locale prête.\n";
    }
    
    private static function createLocalStructure(
        string $baseDir,
        bool $installDoctrine,
        bool $installAuth,
        bool $installApi = false,
        bool $installVision = false,
        bool $installSecure = false,
        ?TemplateRepository $templates = null,
        bool $useTailwind = false
    ): void
    {
        $templates ??= new TemplateRepository(dirname(__DIR__) . '/templates/installer');
        self::createLocalApplicationFiles(
            $baseDir,
            $installDoctrine,
            $installAuth,
            $installApi,
            $installVision,
            $installSecure,
            $templates,
            $useTailwind
        );
        self::createLocalEnvironment($baseDir, $installDoctrine, $installApi);

        echo "✅ Structure locale créée.\n";
    }

    private static function createLocalApplicationFiles(
        string $baseDir,
        bool $installDoctrine,
        bool $installAuth,
        bool $installApi = false,
        bool $installVision = false,
        bool $installSecure = false,
        ?TemplateRepository $templates = null,
        bool $useTailwind = false
    ): void
    {
        $templates ??= new TemplateRepository(dirname(__DIR__) . '/templates/installer');
        $publicDir = $baseDir . '/public';
        $viewsDir = $baseDir . '/views';
        $templatesDir = $viewsDir . '/_templates';
        $homeDir = $viewsDir . '/home';

        foreach ([$publicDir, $viewsDir, $templatesDir, $homeDir] as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
        }

        self::createHtaccess($publicDir);
        self::createHeaderTemplate($templatesDir, $installVision, $templates, $useTailwind);
        self::createFooterTemplate($templatesDir, $templates);
        self::createHomeView($homeDir, $installVision, $templates, $useTailwind);
        self::createFrontendAssets($baseDir, $useTailwind);
        self::createLocalDirectories($baseDir);
        self::createConfigDatabase($baseDir, $installDoctrine);
        if ($installAuth) {
            self::createAuthFiles($baseDir);
        }
        if ($installApi) {
            self::createApiFiles($baseDir);
        }
        self::createBootstrapServices($baseDir, $installDoctrine || $installAuth || $installApi);
        self::createWwwGitignore($baseDir);
        self::createPublicIndex($publicDir, $installDoctrine, $installAuth, $installApi, $installSecure, $templates);
    }

    private static function createLocalEnvironment(string $baseDir, bool $hasDoctrine, bool $hasApi = false): void
    {
        self::createLocalEnvFiles($baseDir, $hasDoctrine, $hasApi);
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
        self::createUploadsHtaccess($publicDir . '/uploads');
        
        // Fixer les permissions pour Linux (après création de tous les dossiers)
        self::fixPermissions($baseDir, false);
    }
    
    private static function configureEnv(bool $hasDatabase, bool $hasApi = false, ?string $baseDir = null): void
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

        self::validateDockerConfiguration($envData, $hasDatabase);
        
        // Stocker les noms de conteneurs pour les utiliser dans docker-compose.yml
        self::$containerNames = ['apache' => $envData['APACHE_CONTAINER']];
        if ($hasDatabase) {
            self::$containerNames['mariadb'] = $envData['MARIADB_CONTAINER'];
        }
        
        self::createEnvFile($baseDir ?? self::getProjectRoot(), $envData, $hasDatabase, $hasApi);
        
        echo "✅ Fichier .env créé.\n";
    }
    
    private static function askInput(string $question, string $default = ''): string
    {
        if (self::isNonInteractive()) {
            return $default;
        }

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

    private static function isNonInteractive(): bool
    {
        $value = getenv('PHP_SKELETON_NON_INTERACTIVE');
        if ($value === false) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Valide les valeurs saisies pour la configuration Docker.
     *
     * @param array<string, string> $data
     * @throws \RuntimeException Si une valeur est invalide
     */
    private static function validateDockerConfiguration(array $data, bool $hasDatabase): void
    {
        foreach (['APACHE_CONTAINER' => 'nom du container Apache'] as $key => $label) {
            self::validateDockerIdentifier($key, $label, $data[$key] ?? null);
        }

        $apachePort = self::validateDockerPort('APACHE_PORT', $data['APACHE_PORT'] ?? null);

        if (!$hasDatabase) {
            return;
        }

        self::validateDockerIdentifier('MARIADB_CONTAINER', 'nom du container MariaDB', $data['MARIADB_CONTAINER'] ?? null);
        $mariadbPort = self::validateDockerPort('MARIADB_PORT', $data['MARIADB_PORT'] ?? null);
        if ($apachePort === $mariadbPort) {
            throw new \RuntimeException(
                "Collision de ports Docker: APACHE_PORT et MARIADB_PORT utilisent tous les deux {$apachePort}."
            );
        }
        self::validateDatabaseIdentifier('MYSQL_DATABASE', 'nom de la base de données', $data['MYSQL_DATABASE'] ?? null);
        self::validateDatabaseIdentifier('MYSQL_USER', 'utilisateur MariaDB', $data['MYSQL_USER'] ?? null);

        foreach (['MYSQL_ROOT_PASSWORD', 'MYSQL_PASSWORD'] as $key) {
            if (array_key_exists($key, $data)) {
                self::validateSecretInput($key, $data[$key]);
            }
        }

        if (array_key_exists('PHP_DISPLAY_ERRORS', $data)
            && !in_array(strtolower((string) $data['PHP_DISPLAY_ERRORS']), ['on', 'off'], true)) {
            throw new \RuntimeException('PHP_DISPLAY_ERRORS doit valoir On ou Off.');
        }
    }

    private static function validateDockerIdentifier(string $key, string $label, mixed $value): void
    {
        if (!is_string($value) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/i', $value)) {
            throw new \RuntimeException(
                "Valeur invalide pour {$label} ({$key}). Utilisez uniquement des lettres, chiffres, tirets et underscores."
            );
        }
    }

    private static function validateDockerPort(string $key, mixed $value): int
    {
        $port = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 65535]]
        );

        if ($port === false) {
            throw new \RuntimeException(
                "Port invalide pour {$key}. Utilisez un entier compris entre 1 et 65535."
            );
        }

        return $port;
    }

    private static function validateDatabaseIdentifier(string $key, string $label, mixed $value): void
    {
        if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $value)) {
            throw new \RuntimeException(
                "Valeur invalide pour {$label} ({$key}). Utilisez uniquement des lettres, chiffres et underscores."
            );
        }
    }

    private static function validateSecretInput(string $key, mixed $value): void
    {
        if (!is_string($value) || $value === '' || strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \RuntimeException(
                "Valeur invalide pour {$key}. La valeur doit être non vide et ne contenir aucun caractère de contrôle."
            );
        }
    }
    
    private static function createEnvFile(string $baseDir, array $data, bool $hasDatabase, bool $hasApi = false): void
    {
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
        self::createDockerfile($baseDir, $hasDatabase);
        self::createProductionDockerfile($baseDir, $hasDatabase);
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
      test: ["CMD", "wget", "--quiet", "--tries=1", "--output-document=/dev/null", "http://localhost/health"]
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
      test: ["CMD", "wget", "--quiet", "--tries=1", "--output-document=/dev/null", "http://localhost/health"]
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
    
    private static function createDockerfile(string $baseDir, bool $hasDatabase): void
    {
        $apacheDir = $baseDir . '/apache';
        if (!is_dir($apacheDir)) {
            mkdir($apacheDir, 0755, true);
        }
        
        $databaseExtensions = $hasDatabase ? ' pdo pdo_mysql' : '';
        $content = <<<'DOCKERFILE'
FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
  git \
  unzip \
  wget \
  curl \
  libonig-dev \
  && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j$(nproc) mbstring opcache__DATABASE_EXTENSIONS__

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

        $content = str_replace('__DATABASE_EXTENSIONS__', $databaseExtensions, $content);
        
        file_put_contents($apacheDir . '/Dockerfile', $content);
    }

    private static function createProductionDockerfile(string $baseDir, bool $hasDatabase): void
    {
        $apacheDir = $baseDir . '/apache';
        if (!is_dir($apacheDir)) {
            mkdir($apacheDir, 0755, true);
        }

        $databaseExtensions = $hasDatabase ? ' pdo pdo_mysql' : '';
        $content = <<<'DOCKERFILE'
FROM composer:2 AS dependencies

WORKDIR /app
COPY www/composer.json www/composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
  wget \
  libonig-dev \
  && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j$(nproc) mbstring opcache__DATABASE_EXTENSIONS__

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

        $content = str_replace('__DATABASE_EXTENSIONS__', $databaseExtensions, $content);

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
        bool $hasSecure = false,
        ?TemplateRepository $templates = null
    ): void
    {
        $templates ??= new TemplateRepository(dirname(__DIR__) . '/templates/installer');
        $wwwDir = dirname($publicDir);
        $controllerDir = $wwwDir . '/src/Controller';
        if (!is_dir($controllerDir)) {
            mkdir($controllerDir, 0755, true);
        }
        
        $indexContent = self::generateIndexContent($hasDoctrine, $hasAuth, $hasApi, $hasSecure, $templates);
        
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
        bool $hasSecure = false,
        ?TemplateRepository $templates = null
    ): string
    {
        $templates ??= new TemplateRepository(dirname(__DIR__) . '/templates/installer');
        $template = $templates->read('environments/common/public/index.php');

        return strtr($template, [
            '{{bootstrap_imports}}' => self::generateBootstrapImports($hasDoctrine, $hasAuth, $hasApi, $hasSecure),
            '{{bootstrap_database}}' => $hasDoctrine
                ? "\n\$dbConfig = \$app->getConfig()->get('database', []);"
                : '',
            '{{bootstrap_container_services}}' => self::generateBootstrapContainerServices($hasDoctrine, $hasAuth),
            '{{bootstrap_security}}' => self::generateBootstrapSecurity($hasSecure),
            '{{bootstrap_web_middlewares}}' => self::generateBootstrapWebMiddlewares($hasSecure, $hasApi),
            '{{bootstrap_auth_routes}}' => $hasAuth
                ? "    \$router->registerRoutes(\\App\\Controller\\AuthController::class);\n"
                : '',
            '{{bootstrap_api_routes}}' => self::generateBootstrapApiRoutes($hasApi),
        ]);
    }

    private static function generateBootstrapImports(
        bool $hasDoctrine,
        bool $hasAuth,
        bool $hasApi,
        bool $hasSecure
    ): string
    {
        $imports = [];

        if ($hasDoctrine) {
            $imports[] = 'use JulienLinard\\Doctrine\\EntityManager;';
        }

        if ($hasAuth) {
            $imports[] = 'use JulienLinard\\Auth\\AuthManager;';
        }

        if ($hasApi) {
            $imports[] = 'use App\\Controller\\ProductController;';
            $imports[] = 'use JulienLinard\\Core\\Middleware\\CorsMiddleware;';
        }

        if ($hasApi || $hasSecure) {
            $imports[] = 'use JulienLinard\\Core\\Middleware\\RateLimitMiddleware;';
            $imports[] = 'use JulienLinard\\Core\\Middleware\\RequestValidationMiddleware;';
        }

        if ($hasSecure) {
            $imports[] = 'use JulienLinard\\Core\\Middleware\\CompressionMiddleware;';
            $imports[] = 'use JulienLinard\\Core\\Middleware\\SecurityHeadersMiddleware;';
        }

        return implode("\n", $imports);
    }

    private static function generateBootstrapContainerServices(bool $hasDoctrine, bool $hasAuth): string
    {
        $blocks = [];

        if ($hasDoctrine) {
            $blocks[] = <<<'PHP'
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
            $blocks[] = $hasDoctrine
                ? <<<'PHP'
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
PHP
                : <<<'PHP'
// Enregistrer AuthManager comme singleton
$container->singleton(AuthManager::class, function() {
    return new AuthManager([
        'user_class' => \App\Entity\User::class
    ]);
});
PHP;
        }

        return implode("\n\n", $blocks);
    }

    private static function generateBootstrapSecurity(bool $hasSecure): string
    {
        if (!$hasSecure) {
            return '';
        }

        return <<<'PHP'
// Les middlewares de réponse restent globaux afin de couvrir les réponses
// web et API. Le routeur les applique après le contrôleur.
$router->addMiddleware(new SecurityHeadersMiddleware([
    'hsts' => getenv('APP_ENV') === 'production' ? 'max-age=31536000; includeSubDomains' : null,
    'permissionsPolicy' => 'geolocation=(), camera=(), microphone=()',
]));
$router->addMiddleware(new CompressionMiddleware([
    'minSize' => 1024,
]));
PHP;
    }

    private static function generateBootstrapWebMiddlewares(bool $hasSecure, bool $hasApi): string
    {
        if (!$hasSecure || $hasApi) {
            return '';
        }

        return <<<'PHP'
    new RequestValidationMiddleware(10_485_760),
    new RateLimitMiddleware(100, 60, dirname(__DIR__) . '/storage/cache/rate-limit'),
PHP;
    }

    private static function generateBootstrapApiRoutes(bool $hasApi): string
    {
        if (!$hasApi) {
            return '';
        }

        return <<<'PHP'
// Pipeline API officiel : CORS, validation, limitation de débit.
// Le groupe est stateless : aucun middleware CSRF n'y est enregistré.
$router->group('', [
    new CorsMiddleware(getenv('API_CORS_ORIGINS') ?: ''),
    new RequestValidationMiddleware(10_485_760),
    new RateLimitMiddleware(100, 60, dirname(__DIR__) . '/storage/cache/rate-limit'),
], static function ($router): void {
    $router->registerRoutes(\App\Controller\ProductController::class);
});
PHP;
    }


    
    private static function createHeaderTemplate(
        string $templatesDir,
        bool $useVision = false,
        ?TemplateRepository $templates = null,
        bool $useTailwind = false
    ): void
    {
        $templates ??= new TemplateRepository(dirname(__DIR__) . '/templates/installer');
        if ($useVision) {
            $template = $useTailwind
                ? 'profiles/vision/views/_templates/_header.html.vis'
                : 'profiles/vision/views/_templates/_header.classic.html.vis';
        } else {
            $template = $useTailwind
                ? 'environments/common/views/_templates/_header.html.php'
                : 'environments/common/views/_templates/_header.classic.html.php';
        }

        self::writeGeneratedFile(
            $templatesDir . '/_header.html.php',
            $templates->read($template)
        );
    }
    
    private static function createFooterTemplate(
        string $templatesDir,
        ?TemplateRepository $templates = null
    ): void
    {
        $templates ??= new TemplateRepository(dirname(__DIR__) . '/templates/installer');
        self::writeGeneratedFile(
            $templatesDir . '/_footer.html.php',
            $templates->read('environments/common/views/_templates/_footer.html.php')
        );
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
    
    private static function displayCompletion(
        bool $useDocker,
        bool $hasDoctrine = false,
        bool $hasAuth = false,
        bool $hasApi = false,
        bool $hasVision = false,
        bool $hasSecure = false,
        bool $useTailwind = false
    ): void
    {
        echo "\n";
        echo "╔═══════════════════════════════════════════════════════════╗\n";
        echo "║              Installation terminée avec succès !          ║\n";
        echo "╚═══════════════════════════════════════════════════════════╝\n";
        echo "\n";
        $profiles = ['base'];
        if ($hasDoctrine || $hasAuth || $hasApi) {
            $profiles[] = 'base de données';
        }
        if ($hasAuth) {
            $profiles[] = 'authentification';
        }
        if ($hasApi) {
            $profiles[] = 'API';
        }
        if ($hasVision) {
            $profiles[] = 'Vision';
        }
        if ($hasSecure) {
            $profiles[] = 'sécurisé';
        }

        echo '🔧 Mode: ' . ($useDocker ? 'Docker' : 'local') . "\n";
        echo '🧩 Profils: ' . implode(', ', $profiles) . "\n";
        echo '🎨 Styles: ' . ($useTailwind ? 'Tailwind CSS 4' : 'CSS classique') . "\n";
        echo "🔐 Les secrets sont générés dans les fichiers .env : ne les commitez pas.\n";
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

        if ($useTailwind) {
            echo "   🎨 Tailwind: npm run watch (développement) ou npm run build (production)\n";
        } else {
            echo "   🎨 Styles: modifiez public/assets/app.css\n";
        }
        
        echo "\n";
        echo "📚 Documentation:\n";
        echo "   - Router: https://packagist.org/packages/julienlinard/php-router\n";
        echo "   - Core: https://packagist.org/packages/julienlinard/core-php\n";
        echo "\n";
    }
}
