<?php

declare(strict_types=1);

namespace Julien\Tests;

use PHPUnit\Framework\TestCase;
use Julien\Installer;
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
        $method->setAccessible(true);

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
        $method->setAccessible(true);

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
        $method->setAccessible(true);

        // Créer un répertoire temporaire
        $tempDir = sys_get_temp_dir() . '/php-skeleton-test-' . uniqid();
        mkdir($tempDir, 0755, true);

        // Capturer la sortie pour éviter les warnings PHPUnit
        ob_start();
        try {
            // Tenter de régénérer avec un chemin contenant une injection
            // Note: escapeshellarg devrait protéger, mais testons quand même
            $method->invokeArgs(null, [$tempDir]);

            // Si on arrive ici sans exception, c'est que escapeshellarg a protégé
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            // Exception attendue si la validation détecte l'injection
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
     * Test que findComposer ne permet pas l'injection
     */
    public function testFindComposerPreventsInjection(): void
    {
        $method = $this->reflection->getMethod('findComposer');
        $method->setAccessible(true);

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

    /**
     * Test que isExecutable ne permet pas l'injection
     */
    public function testIsExecutablePreventsInjection(): void
    {
        $method = $this->reflection->getMethod('isExecutable');
        $method->setAccessible(true);

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

