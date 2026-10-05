<?php

declare(strict_types=1);

namespace Julien\Tests;

use Julien\Installer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class InstallerGenerationTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir() . '/php-skeleton-generation-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->projectDir, 0755, true));
    }

    protected function tearDown(): void
    {
        (new ReflectionClass(Installer::class))->getProperty('containerNames')->setValue(null, null);
        $this->removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testBaseProfileIsMinimalAndConfigured(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, false, false);
        $this->invoke($reflection, 'copyComposerJson', $this->projectDir, $this->projectDir, false, false);

        self::assertFileExists($this->projectDir . '/.env');
        self::assertFileExists($this->projectDir . '/.env.example');
        self::assertFileExists($this->projectDir . '/public/index.php');
        self::assertFileExists($this->projectDir . '/src/Controller/HomeController.php');
        self::assertStringContainsString("path: '/health'", (string) file_get_contents($this->projectDir . '/src/Controller/HomeController.php'));
        self::assertFileDoesNotExist($this->projectDir . '/config/database.php');

        $composer = json_decode((string) file_get_contents($this->projectDir . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('^1.4', $composer['require']['julienlinard/core-php']);
        self::assertSame('^1.4', $composer['require']['julienlinard/php-router']);
        self::assertArrayNotHasKey('julienlinard/php-validator', $composer['require']);
        self::assertMatchesRegularExpression('/^APP_SECRET=[a-f0-9]{64}$/m', (string) file_get_contents($this->projectDir . '/.env'));
    }

    public function testAuthProfileAlwaysIncludesDoctrine(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, true, true);
        $this->invoke($reflection, 'copyComposerJson', $this->projectDir, $this->projectDir, true, true);

        $composer = json_decode((string) file_get_contents($this->projectDir . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('julienlinard/doctrine-php', $composer['require']);
        self::assertSame('*', $composer['require']['ext-pdo']);
        self::assertSame('^1.3', $composer['require']['julienlinard/auth-php']);
        self::assertFileExists($this->projectDir . '/config/database.php');
        self::assertFileExists($this->projectDir . '/src/Entity/User.php');
        self::assertFileExists($this->projectDir . '/src/Controller/AuthController.php');
        self::assertFileExists($this->projectDir . '/migrations/20261005_create_users.sql');
        self::assertFileExists($this->projectDir . '/migrations/20261005_create_remember_tokens.sql');
        $index = (string) file_get_contents($this->projectDir . '/public/index.php');
        self::assertStringContainsString('EntityManager', $index);
        self::assertStringContainsString('AuthController::class', $index);

        $authController = (string) file_get_contents($this->projectDir . '/src/Controller/AuthController.php');
        self::assertStringContainsString("path: '/login'", $authController);
        self::assertStringContainsString("path: '/account'", $authController);
        self::assertStringContainsString('new AuthMiddleware()', $authController);
    }

    public function testApiProfileIncludesDoctrineAndApiArtifacts(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, true, false, true, false);
        $this->invoke($reflection, 'copyComposerJson', $this->projectDir, $this->projectDir, true, false, true, false);

        $composer = json_decode((string) file_get_contents($this->projectDir . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('^1.2', $composer['require']['julienlinard/doctrine-php']);
        self::assertSame('^1.3', $composer['require']['julienlinard/php-api']);
        self::assertArrayNotHasKey('julienlinard/auth-php', $composer['require']);
        self::assertFileExists($this->projectDir . '/src/Entity/Product.php');
        self::assertFileExists($this->projectDir . '/src/Controller/ProductController.php');
        self::assertFileExists($this->projectDir . '/migrations/20261005_create_products.sql');

        $index = (string) file_get_contents($this->projectDir . '/public/index.php');
        self::assertStringContainsString('ProductController', $index);
        self::assertStringContainsString('registerRoutes(\\App\\Controller\\ProductController::class)', $index);
    }

    public function testVisionProfileIsOptionalAndUsesVisionTemplates(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, false, false, false, true);
        $this->invoke($reflection, 'copyComposerJson', $this->projectDir, $this->projectDir, false, false, false, true);

        $composer = json_decode((string) file_get_contents($this->projectDir . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('^1.0', $composer['require']['julienlinard/php-vision']);
        self::assertArrayNotHasKey('julienlinard/doctrine-php', $composer['require']);
        self::assertArrayNotHasKey('julienlinard/php-api', $composer['require']);
        self::assertFileExists($this->projectDir . '/views/home/index.html.vis');
        self::assertFileDoesNotExist($this->projectDir . '/views/home/index.html.php');
        self::assertStringContainsString('{{ title }}', (string) file_get_contents($this->projectDir . '/views/home/index.html.vis'));
        self::assertStringNotContainsString('<?php', (string) file_get_contents($this->projectDir . '/views/_templates/_header.html.php'));
    }

    public function testGeneratedComposerCannotBeOverwrittenOnRerun(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, false, false);
        $this->invoke($reflection, 'copyComposerJson', $this->projectDir, $this->projectDir, false, false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ne correspond pas au skeleton source');
        $this->invoke($reflection, 'copyComposerJson', $this->projectDir, $this->projectDir, false, false);
    }

    public function testDockerBaseProfileDoesNotGenerateDatabaseService(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $containerNames = $reflection->getProperty('containerNames');
        $containerNames->setValue(null, ['apache' => 'apache_test']);

        $this->invoke($reflection, 'createDockerCompose', $this->projectDir, false);
        $compose = (string) file_get_contents($this->projectDir . '/docker-compose.yml');

        self::assertStringNotContainsString('mariadb:', $compose);
        self::assertStringNotContainsString('depends_on:', $compose);
        self::assertStringContainsString('http://localhost/health', $compose);

        $containerNames->setValue(null, ['apache' => 'apache_test', 'mariadb' => 'mariadb_test']);
        $this->invoke($reflection, 'createDockerCompose', $this->projectDir, true);
        $compose = (string) file_get_contents($this->projectDir . '/docker-compose.yml');

        self::assertStringContainsString('mariadb_test:', $compose);
        self::assertStringContainsString('depends_on:', $compose);
        self::assertStringContainsString('MYSQL_ROOT_PASSWORD', $compose);
    }

    public function testDockerDevelopmentAndProductionConfigurationsAreSeparated(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $containerNames = $reflection->getProperty('containerNames');
        $containerNames->setValue(null, ['apache' => 'apache_test', 'mariadb' => 'mariadb_test']);

        mkdir($this->projectDir . '/www', 0755, true);
        $this->invoke($reflection, 'createEnvExample', $this->projectDir, $this->projectDir . '/www', true);
        $this->invoke($reflection, 'createDockerFiles', $this->projectDir, true);

        $developmentCompose = (string) file_get_contents($this->projectDir . '/docker-compose.yml');
        $productionCompose = (string) file_get_contents($this->projectDir . '/docker-compose.prod.yml');
        $productionDockerfile = (string) file_get_contents($this->projectDir . '/apache/Dockerfile.prod');
        $productionIni = (string) file_get_contents($this->projectDir . '/apache/custom-php-prod.ini');

        self::assertStringContainsString('build: apache', $developmentCompose);
        self::assertStringContainsString('./www:/var/www/html', $developmentCompose);
        self::assertStringContainsString('dockerfile: apache/Dockerfile.prod', $productionCompose);
        self::assertStringNotContainsString('./www:/var/www/html', $productionCompose);
        self::assertStringContainsString('APP_ENV: production', $productionCompose);
        self::assertStringContainsString('APP_DEBUG: "0"', $productionCompose);
        self::assertStringContainsString('FROM composer:2 AS dependencies', $productionDockerfile);
        self::assertStringContainsString('composer install --no-dev', $productionDockerfile);
        self::assertStringContainsString('display_errors = Off', $productionIni);
        self::assertStringContainsString('opcache.validate_timestamps = 0', $productionIni);

        self::assertFileExists($this->projectDir . '/www/.env.production.example');
        $productionEnv = (string) file_get_contents($this->projectDir . '/www/.env.production.example');
        self::assertStringContainsString("APP_ENV=production", $productionEnv);
        self::assertStringContainsString("APP_DEBUG=0", $productionEnv);
    }

    private function invoke(ReflectionClass $reflection, string $method, mixed ...$arguments): void
    {
        $reflection->getMethod($method)->invoke(null, ...$arguments);
    }

    private function invokeSilently(ReflectionClass $reflection, string $method, mixed ...$arguments): void
    {
        ob_start();
        try {
            $this->invoke($reflection, $method, ...$arguments);
        } finally {
            ob_end_clean();
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
