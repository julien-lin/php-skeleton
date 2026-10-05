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
{{bootstrap_imports}}
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
{{bootstrap_database}}
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
{{bootstrap_container_services}}
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
{{bootstrap_security}}
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

// Pipeline web officiel : CSRF, validation, limitation de débit.
// Ces middlewares s'appliquent aux routes HTML et aux routes d'authentification,
// mais pas au groupe API stateless ci-dessous.
$router->group('', [
    new CsrfMiddleware(),{{bootstrap_web_middlewares}}], static function ($router): void {
    $router->registerRoutes(HomeController::class);
{{bootstrap_auth_routes}}});
{{bootstrap_api_routes}}// Démarrer l'application
$app->start();

// Traiter la requête HTTP
$app->handle();
