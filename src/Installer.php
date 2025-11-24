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
        
        if ($installDoctrine) {
            self::installPackage('julienlinard/doctrine-php');
        }
        
        if ($installAuth) {
            self::installPackage('julienlinard/auth-php');
        }
        
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
    
    private static function installPackage(string $package): void
    {
        echo "\n📦 Installation de {$package}...\n";
        
        $composerPath = self::findComposer();
        if (!$composerPath) {
            echo "❌ Erreur: Composer n'est pas disponible dans le PATH.\n";
            echo "   Veuillez installer {$package} manuellement:\n";
            echo "   composer require {$package}\n";
            return;
        }
        
        $command = escapeshellarg($composerPath) . ' require ' . escapeshellarg($package) . ' --no-interaction';
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
        
        $baseDir = dirname(__DIR__, 2);
        
        self::createDockerFiles($baseDir);
        
        echo "✅ Fichiers Docker créés.\n";
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
        $baseDir = dirname(__DIR__, 2);
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
        self::createHtaccess($baseDir);
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
      - ./public:/var/www/html/public
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
    
    private static function createHtaccess(string $baseDir): void
    {
        $publicDir = $baseDir . '/public';
        if (!is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }
        
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
            echo "   3. Visitez http://localhost\n";
            echo "   4. Utilisez 'ccomposer' pour les commandes Composer\n";
        } else {
            echo "   1. Configurez votre fichier .env si nécessaire\n";
            echo "   2. Lancez votre serveur: php -S localhost:8000 -t public\n";
            echo "   3. Visitez http://localhost:8000\n";
        }
        
        echo "\n";
        echo "📚 Documentation:\n";
        echo "   - Router: https://packagist.org/packages/julienlinard/php-router\n";
        echo "   - Core: https://packagist.org/packages/julienlinard/core-php\n";
        echo "\n";
    }
}

