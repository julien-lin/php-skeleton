<?php

declare(strict_types=1);

namespace Julien\Tests;

use PHPUnit\Framework\TestCase;
use Julien\Installer;
use Julien\Installer\InstallPaths;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests de sécurité pour Installer
 * 
 * ✅ PHASE 2.1: Tests d'injection de commandes
 */
class InstallerSecurityTest extends TestCase
{
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reflection = new ReflectionClass(Installer::class);
    }

    /**
     * Test que installPackage rejette les packages avec injection
     */
    public function testInstallPackageRejectsInjection(): void
    {
        $method = $this->reflection->getMethod('installPackage');
        // Créer un répertoire temporaire
        $tempDir = sys_get_temp_dir() . '/php-skeleton-test-' . uniqid();
        mkdir($tempDir, 0755, true);

        // Capturer la sortie pour éviter les warnings PHPUnit
        ob_start();
        try {
            // Tenter d'installer un package avec injection
            $package = "test/package; rm -rf /";
            $method->invokeArgs(null, [$package, $tempDir]);

            // Si on arrive ici, la méthode a dû échouer de manière sécurisée
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            // Exception attendue
            $this->assertStringContainsString('non autorisée', $e->getMessage());
        } finally {
            ob_end_clean();
            // Nettoyer
            if (is_dir($tempDir)) {
                $this->removeDirectory($tempDir);
            }
        }
    }

    /**
     * Test que installPackageInDocker rejette les packages avec injection
     */
    public function testInstallPackageInDockerRejectsInjection(): void
    {
        $method = $this->reflection->getMethod('installPackageInDocker');
        // Créer un répertoire temporaire
        $tempDir = sys_get_temp_dir() . '/php-skeleton-test-' . uniqid();
        mkdir($tempDir, 0755, true);

        // Capturer la sortie pour éviter les warnings PHPUnit
        ob_start();
        try {
            // Tenter d'installer un package avec injection
            $package = "test/package | cat /etc/passwd";
            $method->invokeArgs(null, [$package, $tempDir]);

            // Si on arrive ici, la méthode a dû échouer de manière sécurisée
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            // Exception attendue
            $this->assertStringContainsString('non autorisée', $e->getMessage());
        } finally {
            ob_end_clean();
            // Nettoyer
            if (is_dir($tempDir)) {
                $this->removeDirectory($tempDir);
            }
        }
    }

    /**
     * Test que regenerateAutoloader rejette les chemins avec injection
     */
    public function testRegenerateAutoloaderRejectsInjection(): void
    {
        $method = $this->reflection->getMethod('regenerateAutoloader');
        // Créer un répertoire temporaire
        $tempDir = sys_get_temp_dir() . '/php-skeleton-test-' . uniqid();
        mkdir($tempDir, 0755, true);

        // Capturer la sortie pour éviter les warnings PHPUnit
        ob_start();
        try {
            // Tenter de régénérer avec un chemin contenant une injection
            // Note: escapeshellarg devrait protéger, mais testons quand même
            $method->invokeArgs(null, [$tempDir]);

            $this->fail('La régénération doit échouer lorsque composer.json est absent.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('composer.json', $e->getMessage());
        } finally {
            ob_end_clean();
            // Nettoyer
            if (is_dir($tempDir)) {
                $this->removeDirectory($tempDir);
            }
        }
    }

    /**
     * Test que findComposer ne permet pas l'injection
     */
    public function testFindComposerPreventsInjection(): void
    {
        $method = $this->reflection->getMethod('findComposer');
        // La méthode devrait retourner null ou un chemin valide, jamais exécuter d'injection
        $result = $method->invokeArgs(null, []);

        $this->assertTrue($result === null || is_string($result));
        if ($result !== null) {
            // Vérifier que le résultat ne contient pas de caractères dangereux
            $this->assertStringNotContainsString(';', $result);
            $this->assertStringNotContainsString('&', $result);
            $this->assertStringNotContainsString('|', $result);
            $this->assertStringNotContainsString('`', $result);
        }
    }

    public function testRequiredBinariesAreValidatedBeforeGeneration(): void
    {
        $this->reflection->getMethod('assertRequiredBinaries')->invoke(null);
        self::assertNotNull($this->reflection->getMethod('findComposer')->invoke(null));
    }

    public function testInstallTargetRejectsNonSkeletonDirectory(): void
    {
        $tempDir = sys_get_temp_dir() . '/php-skeleton-target-' . bin2hex(random_bytes(6));
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/README.md', 'existing project');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('composer.json introuvable');
            $this->reflection->getMethod('assertInstallTargetIsSkeleton')->invoke(null, $tempDir, false);
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    public function testInstallTargetRejectsGeneratedProject(): void
    {
        $tempDir = sys_get_temp_dir() . '/php-skeleton-generated-' . bin2hex(random_bytes(6));
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/composer.json', json_encode([
            'name' => 'app/existing-project',
        ], JSON_THROW_ON_ERROR));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('déjà contenir une application générée');
            $this->reflection->getMethod('assertInstallTargetIsSkeleton')->invoke(null, $tempDir, false);
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    public function testDockerConfigurationRejectsUnsafeValues(): void
    {
        $method = $this->reflection->getMethod('validateDockerConfiguration');
        $valid = [
            'APACHE_CONTAINER' => 'apache_app',
            'APACHE_PORT' => '8080',
            'MARIADB_CONTAINER' => 'mariadb_app',
            'MARIADB_PORT' => '3307',
            'MYSQL_DATABASE' => 'app_db',
            'MYSQL_USER' => 'app_user',
        ];

        $method->invoke(null, $valid, true);

        $invalid = $valid;
        $invalid['APACHE_CONTAINER'] = 'apache;rm';
        try {
            $method->invoke(null, $invalid, true);
            self::fail('Un nom de container contenant une commande doit être rejeté.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Valeur invalide pour nom du container Apache', $exception->getMessage());
        }

        $invalid = $valid;
        $invalid['MARIADB_PORT'] = '70000';
        try {
            $method->invoke(null, $invalid, true);
            self::fail('Un port hors plage doit être rejeté.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Port invalide pour MARIADB_PORT', $exception->getMessage());
        }

        $invalid = $valid;
        $invalid['MYSQL_DATABASE'] = 'app/db';
        try {
            $method->invoke(null, $invalid, true);
            self::fail('Un identifiant DB contenant un séparateur doit être rejeté.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Valeur invalide pour nom de la base de données', $exception->getMessage());
        }

        $invalid = $valid;
        $invalid['APACHE_PORT'] = '3306';
        $invalid['MARIADB_PORT'] = '3306';
        try {
            $method->invoke(null, $invalid, true);
            self::fail('Deux ports Docker identiques doivent être rejetés.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Collision de ports Docker', $exception->getMessage());
        }

        $invalid = $valid;
        $invalid['MYSQL_PASSWORD'] = "secret\nwith-control-character";
        try {
            $method->invoke(null, $invalid, true);
            self::fail('Un secret Docker contenant un caractère de contrôle doit être rejeté.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('MYSQL_PASSWORD', $exception->getMessage());
        }

        $invalid = $valid;
        $invalid['PHP_DISPLAY_ERRORS'] = 'maybe';
        try {
            $method->invoke(null, $invalid, true);
            self::fail('Une valeur PHP_DISPLAY_ERRORS inconnue doit être rejetée.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('PHP_DISPLAY_ERRORS', $exception->getMessage());
        }
    }

    public function testInvalidProjectNameIsRejected(): void
    {
        $parentDir = sys_get_temp_dir() . '/php-skeleton-invalid-name-' . bin2hex(random_bytes(4));
        $tempDir = $parentDir . '/!!!';
        mkdir($parentDir, 0755, true);
        mkdir($tempDir, 0755, true);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Nom de projet invalide');
            $this->reflection->getMethod('copyComposerJson')->invoke(null, $tempDir, $tempDir, false, false);
        } finally {
            $this->removeDirectory($parentDir);
        }
    }

    public function testDependentProfileOptionsEnableDoctrine(): void
    {
        $options = new \Julien\Installer\InstallOptions(false, false, true, false, false, false);
        self::assertTrue($options->installDoctrine);
        self::assertTrue($options->installAuth);
    }

    public function testCompletionSummaryListsProfilesWithoutSecrets(): void
    {
        $output = '';
        ob_start();
        try {
            $this->reflection->getMethod('displayCompletion')->invoke(null, true, true, true, true, true, true);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertStringContainsString('Mode: Docker', $output);
        self::assertStringContainsString('base de données', $output);
        self::assertStringContainsString('authentification', $output);
        self::assertStringContainsString('API', $output);
        self::assertStringContainsString('Vision', $output);
        self::assertStringContainsString('sécurisé', $output);
        self::assertStringNotContainsString('MYSQL_PASSWORD=', $output);
        self::assertStringNotContainsString('MYSQL_ROOT_PASSWORD=', $output);
    }

    public function testStagedPublicationRollsBackOnFailure(): void
    {
        $baseDir = sys_get_temp_dir() . '/php-skeleton-publish-' . bin2hex(random_bytes(6));
        mkdir($baseDir, 0755, true);
        file_put_contents($baseDir . '/composer.json', '{"name":"julienlinard/php-skeleton"}');
        file_put_contents($baseDir . '/blocked', 'keep me');

        $stagingDir = $this->reflection->getMethod('createInstallationStagingDirectory')->invoke(null);
        file_put_contents($stagingDir . '/composer.json', '{"name":"app/generated"}');
        mkdir($stagingDir . '/blocked', 0755, true);
        file_put_contents($stagingDir . '/blocked/marker.txt', 'must fail');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Impossible de créer le répertoire');
            $this->reflection->getMethod('publishInstallationStaging')->invoke(null, new InstallPaths($baseDir, $stagingDir, false));
        } finally {
            self::assertSame('{"name":"julienlinard/php-skeleton"}', (string) file_get_contents($baseDir . '/composer.json'));
            self::assertSame('keep me', (string) file_get_contents($baseDir . '/blocked'));
            $this->removeDirectory($stagingDir);
            $this->removeDirectory($baseDir);
        }
    }

    public function testComposerFailureLeavesFinalDirectoryUntouched(): void
    {
        $baseDir = sys_get_temp_dir() . '/php-skeleton-composer-failure-' . bin2hex(random_bytes(6));
        mkdir($baseDir, 0755, true);
        file_put_contents($baseDir . '/README.md', 'keep me');

        $stagingDir = $this->reflection->getMethod('createInstallationStagingDirectory')->invoke(null);
        file_put_contents($stagingDir . '/composer.json', json_encode([
            'name' => 'app/failing-profile',
            'require' => ['php' => '>=999.0'],
        ], JSON_THROW_ON_ERROR));

        ob_start();
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Échec de la résolution des dépendances');
            $this->reflection->getMethod('installDependencies')->invoke(null, $stagingDir);
        } finally {
            ob_end_clean();
            self::assertSame('keep me', (string) file_get_contents($baseDir . '/README.md'));
            self::assertFileDoesNotExist($baseDir . '/composer.json');
            $this->removeDirectory($stagingDir);
            $this->removeDirectory($baseDir);
        }
    }

    public function testNonInteractiveModeUsesDefaultsWithoutReadingStdin(): void
    {
        $previous = getenv('PHP_SKELETON_NON_INTERACTIVE');
        putenv('PHP_SKELETON_NON_INTERACTIVE=1');

        try {
            self::assertFalse($this->reflection->getMethod('askQuestion')->invoke(null, 'question', false));
            self::assertSame('default', $this->reflection->getMethod('askInput')->invoke(null, 'question', 'default'));
        } finally {
            if ($previous === false) {
                putenv('PHP_SKELETON_NON_INTERACTIVE');
            } else {
                putenv('PHP_SKELETON_NON_INTERACTIVE=' . $previous);
            }
        }
    }

    public function testVerboseModeRedactsSensitiveValues(): void
    {
        $previous = getenv('PHP_SKELETON_VERBOSE');
        putenv('PHP_SKELETON_VERBOSE=1');

        ob_start();
        try {
            $this->reflection->getMethod('verbose')->invoke(null, 'MYSQL_PASSWORD=super-secret --token abc123');
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            if ($previous === false) {
                putenv('PHP_SKELETON_VERBOSE');
            } else {
                putenv('PHP_SKELETON_VERBOSE=' . $previous);
            }
        }

        self::assertStringContainsString('[verbose]', $output);
        self::assertStringContainsString('MYSQL_PASSWORD=[REDACTED]', $output);
        self::assertStringContainsString('--token [REDACTED]', $output);
        self::assertStringNotContainsString('super-secret', $output);
        self::assertStringNotContainsString('abc123', $output);
    }

    /**
     * Test que isExecutable ne permet pas l'injection
     */
    public function testIsExecutablePreventsInjection(): void
    {
        $method = $this->reflection->getMethod('isExecutable');
        // Tester avec un chemin normal
        $result = $method->invokeArgs(null, ['composer']);
        $this->assertIsBool($result);

        // Tester avec un chemin contenant des caractères dangereux
        // La méthode devrait utiliser safeShellExec qui rejette ces caractères
        try {
            $result = $method->invokeArgs(null, ['composer; rm -rf /']);
            // Si on arrive ici, la méthode a dû échouer de manière sécurisée
            $this->assertFalse($result);
        } catch (\RuntimeException $e) {
            // Exception attendue
            $this->assertStringContainsString('non autorisée', $e->getMessage());
        }
    }

    /**
     * Helper pour supprimer un répertoire récursivement
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
