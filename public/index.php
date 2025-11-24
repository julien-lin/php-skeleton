<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use JulienLinard\Core\Application;
use JulienLinard\Core\Controller\Controller;
use JulienLinard\Router\Attributes\Route;
use JulienLinard\Router\Response;

$app = Application::create(__DIR__ . '/..');
$app->setViewsPath(__DIR__ . '/../templates');

$router = $app->getRouter();

class HomeController extends Controller
{
    #[Route(path: '/', methods: ['GET'], name: 'home')]
    public function index(): Response
    {
        return $this->json([
            'message' => 'Hello World!',
            'status' => 'success',
            'framework' => 'PHP Skeleton by Julien Linard'
        ]);
    }
}

$router->registerRoutes(HomeController::class);
$app->start();
$app->handle();

