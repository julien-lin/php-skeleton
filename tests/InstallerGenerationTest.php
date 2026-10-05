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
        self::assertFileDoesNotExist($this->projectDir . '/src/Entity/User.php');
        self::assertFileDoesNotExist($this->projectDir . '/src/Entity/Product.php');
        self::assertFileDoesNotExist($this->projectDir . '/src/Controller/AuthController.php');
        self::assertFileDoesNotExist($this->projectDir . '/src/Controller/ProductController.php');
        self::assertFileDoesNotExist($this->projectDir . '/views/home/index.html.vis');

        $composer = json_decode((string) file_get_contents($this->projectDir . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertMatchesRegularExpression('/^app\/[a-z0-9-]+$/', $composer['name']);
        self::assertStringNotContainsString('your-vendor/', (string) file_get_contents($this->projectDir . '/composer.json'));
        self::assertSame('^1.4', $composer['require']['julienlinard/core-php']);
        self::assertSame('^1.4', $composer['require']['julienlinard/php-router']);
        self::assertSame('*', $composer['require']['ext-mbstring']);
        self::assertArrayNotHasKey('julienlinard/php-validator', $composer['require']);
        self::assertMatchesRegularExpression('/^APP_SECRET=[a-f0-9]{64}$/m', (string) file_get_contents($this->projectDir . '/.env'));
    }

    public function testGeneratedEnvironmentValidatorEnforcesSafeDefaults(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, false, false);
        require_once $this->projectDir . '/src/Service/EnvValidator.php';

        $validatorSource = (string) file_get_contents($this->projectDir . '/src/Service/EnvValidator.php');
        self::assertStringContainsString('extension_loaded($extension)', $validatorSource);
        self::assertStringContainsString("'mbstring'", $validatorSource);

        $probeValidator = $this->projectDir . '/EnvValidatorProbe.php';
        file_put_contents(
            $probeValidator,
            str_replace("'mbstring'", "'php_skeleton_missing_extension'", $validatorSource)
        );

        $extensionProbe = $this->projectDir . '/check-extensions.php';
        file_put_contents($extensionProbe, <<<'PHP'
<?php

require __DIR__ . '/EnvValidatorProbe.php';

try {
    \App\Service\EnvValidator::validate();
} catch (\RuntimeException $exception) {
    echo $exception->getMessage();
    exit(0);
}

exit(1);
PHP);
        $probe = proc_open(
            [PHP_BINARY, $extensionProbe],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $probePipes,
            $this->projectDir
        );
        self::assertIsResource($probe);
        $probeOutput = stream_get_contents($probePipes[1]) . stream_get_contents($probePipes[2]);
        fclose($probePipes[1]);
        fclose($probePipes[2]);
        self::assertSame(0, proc_close($probe));
        self::assertStringContainsString(
            'Extensions PHP requises manquantes: php_skeleton_missing_extension',
            $probeOutput
        );

        $env = (string) file_get_contents($this->projectDir . '/.env');
        self::assertMatchesRegularExpression('/^APP_SECRET=[a-f0-9]{64}$/m', $env);
        self::assertSame(1, preg_match('/^APP_SECRET=([a-f0-9]{64})$/m', $env, $secretMatches));
        $secret = $secretMatches[1];
        $originalSecret = getenv('APP_SECRET');
        $originalLocale = getenv('APP_LOCALE');
        $originalEnvironment = getenv('APP_ENV');
        $originalDebug = getenv('APP_DEBUG');

        try {
            putenv('APP_SECRET');
            putenv('APP_LOCALE=fr');
            $this->expectRuntimeExceptionFromEnvValidator("APP_SECRET n'est pas défini");

            putenv('APP_SECRET=' . $secret);
            \App\Service\EnvValidator::validate();

            putenv('APP_SECRET=too-short');
            $this->expectRuntimeExceptionFromEnvValidator('APP_SECRET doit contenir au moins 32 caractères');

            putenv('APP_SECRET=' . bin2hex(random_bytes(32)));
            putenv('APP_LOCALE=de');
            $this->expectRuntimeExceptionFromEnvValidator("Locale non supportée: 'de'");

            putenv('APP_LOCALE=fr');
            putenv('APP_DEBUG=maybe');
            $this->expectRuntimeExceptionFromEnvValidator('APP_DEBUG doit être défini');

            putenv('APP_DEBUG=1');
            putenv('APP_ENV=qa');
            $this->expectRuntimeExceptionFromEnvValidator("Environnement non supporté: 'qa'");
        } finally {
            $originalSecret === false ? putenv('APP_SECRET') : putenv('APP_SECRET=' . $originalSecret);
            $originalLocale === false ? putenv('APP_LOCALE') : putenv('APP_LOCALE=' . $originalLocale);
            $originalEnvironment === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $originalEnvironment);
            $originalDebug === false ? putenv('APP_DEBUG') : putenv('APP_DEBUG=' . $originalDebug);
        }
    }

    public function testGeneratedFilesDoNotRepeatRuntimeSecretsOutsideEnv(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, true, false);

        $env = (string) file_get_contents($this->projectDir . '/.env');
        self::assertSame(1, preg_match('/^APP_SECRET=([a-f0-9]{64})$/m', $env, $secretMatches));
        self::assertSame(1, preg_match('/^DB_PASS=([^\r\n]+)$/m', $env, $passwordMatches));
        $runtimeSecrets = [$secretMatches[1], $passwordMatches[1]];

        self::assertStringContainsString('/.env', (string) file_get_contents($this->projectDir . '/.gitignore'));
        self::assertStringContainsString('APP_SECRET=', (string) file_get_contents($this->projectDir . '/.env.example'));
        self::assertStringContainsString('DB_PASS=change-me', (string) file_get_contents($this->projectDir . '/.env.example'));

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (!$file->isFile() || in_array($file->getFilename(), ['.env', '.env.example'], true)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            foreach ($runtimeSecrets as $secret) {
                self::assertStringNotContainsString($secret, $contents, $file->getPathname());
            }
        }
    }

    public function testBaseProfileInstallsAndServesHealthRoute(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, false, false);
        $this->invoke($reflection, 'copyComposerJson', $this->projectDir, $this->projectDir, false, false);
        $this->runComposer($this->projectDir, 'install', '--no-dev', '--no-interaction', '--prefer-dist');
        $this->runComposer($this->projectDir, 'validate', '--no-check-publish', '--no-interaction');
        $this->runComposer($this->projectDir, 'audit', '--no-interaction');
        file_put_contents(
            $this->projectDir . '/.env',
            str_replace('APP_DEBUG=1', 'APP_DEBUG=0', (string) file_get_contents($this->projectDir . '/.env'))
        );

        [$exitCode, $output, $errors] = $this->runGeneratedHealthRequest();

        self::assertSame(0, $exitCode, "Code de sortie: {$exitCode}\nErreurs:\n{$errors}\nSortie:\n{$output}");
        self::assertJson($output, $errors . "\nSortie HTTP:\n" . $output);
        self::assertSame([
            'status' => 'ok',
            'framework' => 'php-skeleton',
        ], json_decode($output, true, 512, JSON_THROW_ON_ERROR));

        $incompleteEnv = preg_replace(
            ['/^APP_SECRET=.*$/m', '/^APP_DEBUG=.*$/m'],
            ['APP_SECRET=', 'APP_DEBUG=1'],
            (string) file_get_contents($this->projectDir . '/.env')
        );
        self::assertIsString($incompleteEnv);
        self::assertNotSame(false, file_put_contents($this->projectDir . '/.env', $incompleteEnv));

        [$failureCode, $failureOutput, $failureErrors] = $this->runGeneratedHealthRequest();
        self::assertNotSame(0, $failureCode, $failureOutput . "\n" . $failureErrors);
        self::assertStringContainsString(
            "APP_SECRET n'est pas défini",
            $failureOutput . "\n" . $failureErrors
        );
    }

    public function testSecureProfileAddsOnlyCoreSecurityMiddleware(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, false, false, false, false, true);
        $this->invoke($reflection, 'copyComposerJson', $this->projectDir, $this->projectDir, false, false, false, false, true);

        $composer = json_decode((string) file_get_contents($this->projectDir . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['php', 'julienlinard/core-php', 'julienlinard/php-router', 'ext-mbstring'],
            array_keys($composer['require'])
        );

        $index = (string) file_get_contents($this->projectDir . '/public/index.php');
        self::assertStringContainsString('new RequestValidationMiddleware(10_485_760)', $index);
        self::assertStringContainsString('new RateLimitMiddleware(100, 60', $index);
        self::assertStringContainsString('new SecurityHeadersMiddleware([', $index);
        self::assertStringContainsString('new CompressionMiddleware([', $index);
        self::assertStringContainsString("'/storage/cache/rate-limit'", $index);
        self::assertStringContainsString("'hsts' => getenv('APP_ENV') === 'production'", $index);

        $middlewareOrder = [
            'new CsrfMiddleware()',
            'new RequestValidationMiddleware(10_485_760)',
            'new RateLimitMiddleware(100, 60',
            'new SecurityHeadersMiddleware([',
            'new CompressionMiddleware([',
        ];
        $previousPosition = -1;
        foreach ($middlewareOrder as $middleware) {
            $position = strpos($index, $middleware);
            self::assertNotFalse($position, "Middleware absent du bootstrap généré : {$middleware}");
            self::assertGreaterThan($previousPosition, $position, "Ordre inattendu pour le middleware : {$middleware}");
            $previousPosition = $position;
        }
    }

    public function testSecureProfileInstallsAndServesHealthRoute(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, false, false, false, false, true);
        $this->invoke($reflection, 'copyComposerJson', $this->projectDir, $this->projectDir, false, false, false, false, true);
        $this->runComposer($this->projectDir, 'install', '--no-dev', '--no-interaction', '--prefer-dist');
        $this->runComposer($this->projectDir, 'validate', '--no-check-publish', '--no-interaction');
        $this->runComposer($this->projectDir, 'audit', '--no-interaction');

        [$exitCode, $output, $errors] = $this->runGeneratedHealthRequest();

        self::assertSame(0, $exitCode, "Code de sortie sécurisé: {$exitCode}\nErreurs:\n{$errors}\nSortie:\n{$output}");
        self::assertJson($output, $errors . "\nSortie HTTP sécurisée:\n" . $output);
        self::assertSame([
            'status' => 'ok',
            'framework' => 'php-skeleton',
        ], json_decode($output, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testDatabaseProfileInstallsAndInitializesSqliteEntityManager(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, true, false);
        $this->invoke($reflection, 'copyComposerJson', $this->projectDir, $this->projectDir, true, false);
        $this->runComposer($this->projectDir, 'install', '--no-dev', '--no-interaction', '--prefer-dist');
        $this->runComposer($this->projectDir, 'validate', '--no-check-publish', '--no-interaction');
        $this->runComposer($this->projectDir, 'audit', '--no-interaction');

        require_once $this->projectDir . '/vendor/autoload.php';

        $composer = json_decode((string) file_get_contents($this->projectDir . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('julienlinard/doctrine-php', $composer['require']);
        self::assertArrayNotHasKey('julienlinard/auth-php', $composer['require']);
        self::assertArrayNotHasKey('julienlinard/php-api', $composer['require']);
        self::assertSame('*', $composer['require']['ext-pdo_mysql']);
        self::assertSame('*', $composer['require']['ext-mbstring']);
        self::assertFileExists($this->projectDir . '/config/database.php');
        self::assertMatchesRegularExpression('/^DB_PASS=[a-f0-9]{32}$/m', (string) file_get_contents($this->projectDir . '/.env'));
        self::assertStringNotContainsString('DB_PASS=change-me', (string) file_get_contents($this->projectDir . '/.env'));
        self::assertFileDoesNotExist($this->projectDir . '/src/Entity/User.php');
        self::assertFileDoesNotExist($this->projectDir . '/src/Controller/AuthController.php');
        self::assertFileDoesNotExist($this->projectDir . '/src/Entity/Product.php');
        self::assertFileDoesNotExist($this->projectDir . '/src/Controller/ProductController.php');

        $entityManager = new \JulienLinard\Doctrine\EntityManager([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]);

        self::assertInstanceOf(\PDO::class, $entityManager->getConnection()->getPdo());
    }

    public function testAuthProfileAlwaysIncludesDoctrine(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, true, true);
        $this->invoke($reflection, 'copyComposerJson', $this->projectDir, $this->projectDir, true, true);
        $this->runComposer($this->projectDir, 'validate', '--no-check-publish', '--no-interaction');

        $composer = json_decode((string) file_get_contents($this->projectDir . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('julienlinard/doctrine-php', $composer['require']);
        self::assertSame('*', $composer['require']['ext-pdo']);
        self::assertSame('*', $composer['require']['ext-pdo_mysql']);
        self::assertSame('*', $composer['require']['ext-mbstring']);
        self::assertSame('^1.3', $composer['require']['julienlinard/auth-php']);
        self::assertFileExists($this->projectDir . '/config/database.php');
        self::assertFileExists($this->projectDir . '/src/Entity/User.php');
        self::assertFileExists($this->projectDir . '/src/Controller/AuthController.php');
        self::assertFileExists($this->projectDir . '/migrations/20261005_create_users.sql');
        self::assertFileExists($this->projectDir . '/migrations/20261005_create_remember_tokens.sql');
        self::assertFileDoesNotExist($this->projectDir . '/src/Entity/Product.php');
        self::assertFileDoesNotExist($this->projectDir . '/src/Controller/ProductController.php');
        $index = (string) file_get_contents($this->projectDir . '/public/index.php');
        self::assertStringContainsString('EntityManager', $index);
        self::assertStringContainsString('getConnection()->getPdo()', $index);
        self::assertStringContainsString('Connexion à la base de données impossible', $index);
        self::assertStringContainsString('AuthController::class', $index);

        $authController = (string) file_get_contents($this->projectDir . '/src/Controller/AuthController.php');
        self::assertStringContainsString("path: '/login'", $authController);
        self::assertStringContainsString("path: '/account'", $authController);
        self::assertStringContainsString('new AuthMiddleware()', $authController);
    }

    public function testAuthProfileResolvesGeneratedController(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, true, true);
        $this->invoke($reflection, 'copyComposerJson', $this->projectDir, $this->projectDir, true, true);
        $this->runComposer($this->projectDir, 'install', '--no-dev', '--no-interaction', '--prefer-dist');
        $this->runComposer($this->projectDir, 'audit', '--no-interaction');

        require_once $this->projectDir . '/vendor/autoload.php';

        $entityManager = new \JulienLinard\Doctrine\EntityManager([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]);
        $auth = new \JulienLinard\Auth\AuthManager([
            'user_class' => \App\Entity\User::class,
            'entity_manager' => $entityManager,
        ]);
        $controller = new \App\Controller\AuthController($auth);

        self::assertSame(200, $controller->loginForm()->getStatusCode());

        $invalidLogin = $controller->login(new \JulienLinard\Router\Request('/login', 'POST'));
        self::assertSame(422, $invalidLogin->getStatusCode());
        self::assertSame('application/json', $invalidLogin->getHeaders()['content-type'] ?? null);

        $entityManager->getConnection()->execute(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    roles TEXT NOT NULL DEFAULT 'user',
    permissions TEXT NULL,
    created_at TEXT NULL
)
SQL);

        $plainPassword = 'correct-horse-battery-staple';
        $hashedPassword = password_hash($plainPassword, PASSWORD_BCRYPT);
        $user = new \App\Entity\User('alice@example.com', $hashedPassword);
        $entityManager->persist($user);
        $entityManager->flush();

        self::assertNotSame($plainPassword, $user->getPassword());
        self::assertTrue(password_verify($plainPassword, $user->getPassword()));
        self::assertNotNull($user->getId());

        self::assertFalse($auth->attempt([
            'email' => $user->getEmail(),
            'password' => 'wrong-password',
        ]));
        self::assertTrue($auth->guest());

        $validRequest = $this->createMock(\JulienLinard\Router\Request::class);
        $validRequest->method('getBodyParam')->willReturnCallback(
            static function (string $key, mixed $default = null) use ($plainPassword): mixed {
                return match ($key) {
                    'email' => 'alice@example.com',
                    'password' => $plainPassword,
                    default => $default,
                };
            }
        );

        $validLogin = $controller->login($validRequest);
        self::assertSame(200, $validLogin->getStatusCode());
        self::assertSame(['status' => 'authenticated'], json_decode($validLogin->getContent(), true, 512, JSON_THROW_ON_ERROR));
        self::assertTrue($auth->check());

        $account = $controller->account();
        self::assertSame(200, $account->getStatusCode());
        self::assertSame([
            'id' => $user->getId(),
            'email' => 'alice@example.com',
            'roles' => ['user'],
        ], json_decode($account->getContent(), true, 512, JSON_THROW_ON_ERROR));

        self::assertSame(200, $controller->logout()->getStatusCode());
        self::assertFalse($auth->check());
        self::assertSame([
            'id' => null,
            'email' => null,
            'roles' => null,
        ], json_decode($controller->account()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testDatabaseConfigurationRejectsMissingSecrets(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, true, false);

        $keys = ['DB_NAME', 'MYSQL_DATABASE', 'DB_USER', 'MYSQL_USER', 'DB_PASS', 'MYSQL_PASSWORD'];
        $original = [];
        foreach ($keys as $key) {
            $original[$key] = getenv($key);
            putenv($key);
        }

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Variable d\'environnement obligatoire non définie');
            require $this->projectDir . '/config/database.php';
        } finally {
            foreach ($original as $key => $value) {
                if ($value === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $value);
                }
            }
        }
    }

    public function testDatabaseConfigurationUsesEnvironmentValues(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, true, false);

        $values = [
            'DB_NAME' => 'test_db',
            'DB_USER' => 'test_user',
            'DB_PASS' => 'test_password',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3307',
        ];
        $original = [];
        foreach ($values as $key => $value) {
            $original[$key] = getenv($key);
            putenv($key . '=' . $value);
        }

        try {
            $config = require $this->projectDir . '/config/database.php';
            self::assertSame('test_db', $config['dbname']);
            self::assertSame('test_user', $config['user']);
            self::assertSame('test_password', $config['password']);
            self::assertSame(3307, $config['port']);
            self::assertStringNotContainsString('test_password', (string) file_get_contents($this->projectDir . '/config/database.php'));
        } finally {
            foreach ($original as $key => $value) {
                if ($value === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $value);
                }
            }
        }
    }

    public function testDatabaseConfigurationRejectsInvalidPorts(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, true, false);

        $values = [
            'DB_NAME' => 'test_db',
            'DB_USER' => 'test_user',
            'DB_PASS' => 'test_password',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => 'not-a-port',
        ];
        $original = [];
        foreach ($values as $key => $value) {
            $original[$key] = getenv($key);
            putenv($key . '=' . $value);
        }

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Port de base de données invalide');
            require $this->projectDir . '/config/database.php';
        } finally {
            foreach ($original as $key => $value) {
                if ($value === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $value);
                }
            }
        }
    }

    public function testGeneratedAuthBootstrapReportsConnectionFailure(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, true, true);
        $this->invoke($reflection, 'copyComposerJson', $this->projectDir, $this->projectDir, true, true);
        $this->runComposer($this->projectDir, 'install', '--no-dev', '--no-interaction', '--prefer-dist');
        $this->runComposer($this->projectDir, 'audit', '--no-interaction');

        $sentinelPassword = 'super-secret-not-for-output';
        $env = str_replace(
            ['DB_PORT=3306', 'DB_PASS='],
            ['DB_PORT=1', 'DB_PASS=' . $sentinelPassword],
            (string) file_get_contents($this->projectDir . '/.env')
        );
        self::assertNotSame(false, file_put_contents($this->projectDir . '/.env', $env));

        [$exitCode, $output, $errors] = $this->runGeneratedRequest('/login');
        $failure = $output . "\n" . $errors;

        self::assertNotSame(0, $exitCode, $failure);
        self::assertStringContainsString('Connexion à la base de données impossible', $failure);
        self::assertStringNotContainsString($sentinelPassword, $failure);
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
        self::assertFileDoesNotExist($this->projectDir . '/src/Entity/User.php');
        self::assertFileDoesNotExist($this->projectDir . '/src/Controller/AuthController.php');
        self::assertStringContainsString('API_CORS_ORIGINS=', (string) file_get_contents($this->projectDir . '/.env'));
        self::assertStringContainsString('API_CORS_ORIGINS=', (string) file_get_contents($this->projectDir . '/.env.example'));

        $index = (string) file_get_contents($this->projectDir . '/public/index.php');
        self::assertStringContainsString('ProductController', $index);
        self::assertStringContainsString('registerRoutes(\\App\\Controller\\ProductController::class)', $index);
        self::assertStringContainsString("new CorsMiddleware(getenv('API_CORS_ORIGINS') ?: '')", $index);
        self::assertStringContainsString("new RequestValidationMiddleware(10_485_760, ['/api'])", $index);
        self::assertStringContainsString("new RateLimitMiddleware(100, 60, dirname(__DIR__) . '/storage/cache/rate-limit', ['/api'])", $index);
        self::assertStringContainsString('new CsrfMiddleware()', $index);
    }

    public function testApiProfileResolvesGeneratedDependencies(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, true, false, true, false);
        $this->invoke($reflection, 'copyComposerJson', $this->projectDir, $this->projectDir, true, false, true, false);

        $this->runComposer($this->projectDir, 'install', '--no-dev', '--no-interaction', '--prefer-dist');
        $this->runComposer($this->projectDir, 'validate', '--no-check-publish', '--no-interaction');
        $this->runComposer($this->projectDir, 'audit', '--no-interaction');

        require_once $this->projectDir . '/vendor/autoload.php';

        self::assertTrue(class_exists('App\\Entity\\Product'));
        self::assertTrue(class_exists('App\\Controller\\ProductController'));

        $controller = new \App\Controller\ProductController();
        $routes = (new ReflectionClass($controller))->getMethods();
        $routeCount = 0;
        foreach ($routes as $method) {
            $routeCount += count($method->getAttributes(\JulienLinard\Router\Attributes\Route::class));
        }

        self::assertSame(5, $routeCount);

        $invalidPayload = $controller->create([]);
        self::assertSame(400, $invalidPayload->getStatusCode());
        self::assertSame('application/json', $invalidPayload->getHeaders()['content-type'] ?? null);
        self::assertSame(400, json_decode($invalidPayload->getContent(), true, 512, JSON_THROW_ON_ERROR)['status']);
    }

    public function testVisionProfileIsOptionalAndUsesVisionTemplates(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, false, false, false, true);
        $this->invoke($reflection, 'copyComposerJson', $this->projectDir, $this->projectDir, false, false, false, true);
        $this->runComposer($this->projectDir, 'validate', '--no-check-publish', '--no-interaction');

        $composer = json_decode((string) file_get_contents($this->projectDir . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('^1.0', $composer['require']['julienlinard/php-vision']);
        self::assertSame('vcs', $composer['repositories'][0]['type']);
        self::assertSame('https://github.com/julien-lin/php-vision', $composer['repositories'][0]['url']);
        self::assertArrayNotHasKey('julienlinard/doctrine-php', $composer['require']);
        self::assertArrayNotHasKey('julienlinard/php-api', $composer['require']);
        self::assertFileExists($this->projectDir . '/views/home/index.html.vis');
        self::assertFileDoesNotExist($this->projectDir . '/views/home/index.html.php');
        self::assertStringContainsString('{{ title }}', (string) file_get_contents($this->projectDir . '/views/home/index.html.vis'));
        self::assertStringNotContainsString('<?php', (string) file_get_contents($this->projectDir . '/views/_templates/_header.html.php'));

        $this->runComposer($this->projectDir, 'install', '--no-dev', '--no-interaction', '--prefer-dist');
        $this->runComposer($this->projectDir, 'audit', '--no-interaction');
        file_put_contents(
            $this->projectDir . '/.env',
            str_replace('APP_DEBUG=1', 'APP_DEBUG=0', (string) file_get_contents($this->projectDir . '/.env'))
        );

        [$exitCode, $output, $errors] = $this->runGeneratedRequest('/');
        self::assertSame(0, $exitCode, "Code de sortie Vision: {$exitCode}\nErreurs:\n{$errors}\nSortie:\n{$output}");
        self::assertStringContainsString('Welcome', $output, $errors . "\nSortie:\n" . $output);
        self::assertStringContainsString('PHP Vision', $output, $errors . "\nSortie:\n" . $output);
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

    public function testRerunningGenerationPreservesCustomizedGeneratedFiles(): void
    {
        $reflection = new ReflectionClass(Installer::class);
        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, false, false);

        $homeView = $this->projectDir . '/views/home/index.html.php';
        $customView = "<!-- custom view -->\n";
        file_put_contents($homeView, $customView);

        $this->invokeSilently($reflection, 'createLocalStructure', $this->projectDir, false, false);

        self::assertSame($customView, (string) file_get_contents($homeView));
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
        self::assertStringContainsString('127.0.0.1:${MARIADB_PORT:-3306}:3306', $compose);
        self::assertStringNotContainsString('      - "${MARIADB_PORT:-3306}:3306"', $compose);
        self::assertStringNotContainsString('MYSQL_ROOT_HOST', $compose);
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
        self::assertStringNotContainsString('MYSQL_ROOT_HOST', $productionCompose);
        self::assertStringNotContainsString('MYSQL_ROOT_HOST=%', $productionCompose);
        self::assertStringContainsString('FROM composer:2 AS dependencies', $productionDockerfile);
        self::assertStringContainsString('composer install --no-dev', $productionDockerfile);
        self::assertStringContainsString('display_errors = Off', $productionIni);
        self::assertStringContainsString('opcache.validate_timestamps = 0', $productionIni);

        $applicationEnvExample = (string) file_get_contents($this->projectDir . '/www/.env.example');
        self::assertStringContainsString('DB_HOST=mariadb_app', $applicationEnvExample);
        self::assertStringContainsString('DB_PORT=3306', $applicationEnvExample);
        self::assertStringContainsString('DB_NAME=app_db', $applicationEnvExample);
        self::assertStringNotContainsString('MARIADB_PORT=', $applicationEnvExample);

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

    private function runComposer(string $workingDirectory, string ...$arguments): void
    {
        $pipes = [];
        $process = proc_open(
            array_merge(['composer'], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory
        );

        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $output);
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function runGeneratedHealthRequest(): array
    {
        return $this->runGeneratedRequest('/health');
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function runGeneratedRequest(string $uri): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $this->projectDir . '/public/index.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->projectDir,
            array_merge($_ENV, [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => $uri,
            ])
        );

        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $errors];
    }

    private function expectRuntimeExceptionFromEnvValidator(string $message): void
    {
        try {
            \App\Service\EnvValidator::validate();
            self::fail('Le validateur d’environnement devait rejeter la configuration.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
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
