<?php

declare(strict_types=1);

namespace Julien\Installer;

final class TemplateRepository
{
    public function __construct(private readonly string $rootPath)
    {
        if ($this->rootPath === '') {
            throw new \InvalidArgumentException('Le répertoire racine des templates est requis.');
        }
    }

    public function read(string $relativePath): string
    {
        if (
            $relativePath === ''
            || str_contains($relativePath, '..')
            || str_starts_with($relativePath, DIRECTORY_SEPARATOR)
            || preg_match('/^[a-zA-Z]:[\\\\\/]/', $relativePath) === 1
        ) {
            throw new \InvalidArgumentException("Chemin de template invalide: {$relativePath}");
        }

        $templatePath = $this->rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $content = @file_get_contents($templatePath);
        if ($content === false) {
            throw new \RuntimeException("Template de l'installateur introuvable: {$relativePath}");
        }

        return $content;
    }
}
