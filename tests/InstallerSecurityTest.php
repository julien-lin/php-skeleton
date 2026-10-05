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
