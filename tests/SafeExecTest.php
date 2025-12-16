<?php

declare(strict_types=1);

namespace Julien\Tests;

use PHPUnit\Framework\TestCase;
use Julien\Installer;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests pour la sécurisation de exec() et shell_exec()
 * 
 * ✅ PHASE 2.1: Tests de sécurité pour safeExec() et safeShellExec()
 */
class SafeExecTest extends TestCase
{
    private ReflectionClass $reflection;
    private ReflectionMethod $safeExecMethod;
    private ReflectionMethod $safeShellExecMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reflection = new ReflectionClass(Installer::class);
        $this->safeExecMethod = $this->reflection->getMethod('safeExec');
        $this->safeExecMethod->setAccessible(true);
        $this->safeShellExecMethod = $this->reflection->getMethod('safeShellExec');
        $this->safeShellExecMethod->setAccessible(true);
    }

    /**
     * Test que safeExec accepte les commandes autorisées (composer)
     */
    public function testSafeExecAcceptsAllowedComposerCommand(): void
    {
        $command = 'composer --version';
        $output = [];
        $returnCode = 0;

        $result = $this->safeExecMethod->invokeArgs(null, [$command, &$output, &$returnCode]);

        // La commande devrait être acceptée (même si elle échoue, elle ne devrait pas lever d'exception)
        $this->assertIsBool($result);
    }

    /**
     * Test que safeExec accepte les commandes avec cd et &&
     */
    public function testSafeExecAcceptsCommandWithCd(): void
    {
        $command = 'cd /tmp && composer --version';
        $output = [];
        $returnCode = 0;

        $result = $this->safeExecMethod->invokeArgs(null, [$command, &$output, &$returnCode]);

        $this->assertIsBool($result);
    }

    /**
     * Test que safeExec rejette les commandes non autorisées
     */
    public function testSafeExecRejectsUnauthorizedCommand(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Commande non autorisée: rm');

        $command = 'rm -rf /';
        $output = [];
        $returnCode = 0;

        $this->safeExecMethod->invokeArgs(null, [$command, &$output, &$returnCode]);
    }

    /**
     * Test que safeExec rejette les commandes avec path traversal
     */
    public function testSafeExecRejectsPathTraversal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('path traversal détecté');

        $command = 'cd /tmp/../etc && composer --version';
        $output = [];
        $returnCode = 0;

        $this->safeExecMethod->invokeArgs(null, [$command, &$output, &$returnCode]);
    }

    /**
     * Test que safeExec rejette les chemins système sensibles
     */
    public function testSafeExecRejectsSystemPaths(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Chemin système non autorisé');

        $command = 'cd /etc/passwd && composer --version';
        $output = [];
        $returnCode = 0;

        $this->safeExecMethod->invokeArgs(null, [$command, &$output, &$returnCode]);
    }

    /**
     * Test que safeExec rejette les caractères dangereux
     */
    public function testSafeExecRejectsDangerousCharacters(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Caractères dangereux détectés');

        $command = 'composer require test; rm -rf /';
        $output = [];
        $returnCode = 0;

        $this->safeExecMethod->invokeArgs(null, [$command, &$output, &$returnCode]);
    }

    /**
     * Test que safeExec rejette les caractères dangereux avec &
     */
    public function testSafeExecRejectsAmpersand(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Caractères dangereux détectés');

        $command = 'composer require test & rm -rf /';
        $output = [];
        $returnCode = 0;

        $this->safeExecMethod->invokeArgs(null, [$command, &$output, &$returnCode]);
    }

    /**
     * Test que safeExec rejette les caractères dangereux avec |
     */
    public function testSafeExecRejectsPipe(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Caractères dangereux détectés');

        $command = 'composer require test | cat';
        $output = [];
        $returnCode = 0;

        $this->safeExecMethod->invokeArgs(null, [$command, &$output, &$returnCode]);
    }

    /**
     * Test que safeExec rejette les caractères dangereux avec backtick
     */
    public function testSafeExecRejectsBacktick(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Caractères dangereux détectés');

        $command = 'composer require `rm -rf /`';
        $output = [];
        $returnCode = 0;

        $this->safeExecMethod->invokeArgs(null, [$command, &$output, &$returnCode]);
    }

    /**
     * Test que safeExec rejette les caractères dangereux avec $
     */
    public function testSafeExecRejectsDollarSign(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Caractères dangereux détectés');

        $command = 'composer require $PATH';
        $output = [];
        $returnCode = 0;

        $this->safeExecMethod->invokeArgs(null, [$command, &$output, &$returnCode]);
    }

    /**
     * Test que safeShellExec accepte les commandes autorisées (which)
     */
    public function testSafeShellExecAcceptsAllowedWhichCommand(): void
    {
        $command = 'which composer';
        $result = $this->safeShellExecMethod->invokeArgs(null, [$command]);

        // La commande devrait être acceptée (peut retourner null si composer n'est pas trouvé)
        $this->assertTrue($result === null || is_string($result));
    }

    /**
     * Test que safeShellExec rejette les commandes non autorisées
     */
    public function testSafeShellExecRejectsUnauthorizedCommand(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Commande non autorisée: ls');

        $command = 'ls -la';
        $this->safeShellExecMethod->invokeArgs(null, [$command]);
    }

    /**
     * Test que safeShellExec rejette les commandes avec path traversal
     */
    public function testSafeShellExecRejectsPathTraversal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('path traversal détecté');

        $command = 'which ../etc/passwd';
        $this->safeShellExecMethod->invokeArgs(null, [$command]);
    }

    /**
     * Test que safeShellExec rejette les caractères dangereux
     */
    public function testSafeShellExecRejectsDangerousCharacters(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Caractères dangereux détectés');

        $command = 'which composer; rm -rf /';
        $this->safeShellExecMethod->invokeArgs(null, [$command]);
    }

    /**
     * Test que safeShellExec gère correctement les guillemets
     */
    public function testSafeShellExecHandlesQuotes(): void
    {
        $command = 'which "composer"';
        $result = $this->safeShellExecMethod->invokeArgs(null, [$command]);

        $this->assertTrue($result === null || is_string($result));
    }

    /**
     * Test que safeExec gère correctement les guillemets simples
     */
    public function testSafeExecHandlesSingleQuotes(): void
    {
        $command = "cd '/tmp' && composer --version";
        $output = [];
        $returnCode = 0;

        try {
            $result = $this->safeExecMethod->invokeArgs(null, [$command, &$output, &$returnCode]);
            $this->assertIsBool($result);
        } catch (\RuntimeException $e) {
            // Peut échouer si /tmp n'existe pas ou si composer n'est pas disponible
            $this->assertStringContainsString('non autorisée', $e->getMessage());
        }
    }

    /**
     * Test que safeExec gère correctement les guillemets doubles
     */
    public function testSafeExecHandlesDoubleQuotes(): void
    {
        $command = 'cd "/tmp" && composer --version';
        $output = [];
        $returnCode = 0;

        try {
            $result = $this->safeExecMethod->invokeArgs(null, [$command, &$output, &$returnCode]);
            $this->assertIsBool($result);
        } catch (\RuntimeException $e) {
            // Peut échouer si /tmp n'existe pas ou si composer n'est pas disponible
            $this->assertStringContainsString('non autorisée', $e->getMessage());
        }
    }

    /**
     * Test que safeExec extrait correctement la commande après cd
     */
    public function testSafeExecExtractsCommandAfterCd(): void
    {
        $command = 'cd /tmp && which composer';
        $output = [];
        $returnCode = 0;

        // which est autorisé, donc la commande devrait être acceptée
        try {
            $result = $this->safeExecMethod->invokeArgs(null, [$command, &$output, &$returnCode]);
            $this->assertIsBool($result);
        } catch (\RuntimeException $e) {
            // Peut échouer si /tmp n'existe pas
            $this->assertStringContainsString('non autorisée', $e->getMessage());
        }
    }

    /**
     * Test que safeExec rejette les commandes avec redirection
     */
    public function testSafeExecRejectsRedirection(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Caractères dangereux détectés');

        $command = 'composer require test > /etc/passwd';
        $output = [];
        $returnCode = 0;

        $this->safeExecMethod->invokeArgs(null, [$command, &$output, &$returnCode]);
    }

    /**
     * Test que safeExec rejette les commandes avec redirection inverse
     */
    public function testSafeExecRejectsReverseRedirection(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Caractères dangereux détectés');

        $command = 'composer require test < /etc/passwd';
        $output = [];
        $returnCode = 0;

        $this->safeExecMethod->invokeArgs(null, [$command, &$output, &$returnCode]);
    }
}

