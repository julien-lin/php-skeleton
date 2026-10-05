<?php

declare(strict_types=1);

namespace Julien\Installer;

final class InstallPaths
{
    public readonly string $projectRoot;
    public readonly string $stagingRoot;
    public readonly bool $useDocker;

    public function __construct(string $projectRoot, string $stagingRoot, bool $useDocker)
    {
        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $this->stagingRoot = rtrim($stagingRoot, DIRECTORY_SEPARATOR);
        $this->useDocker = $useDocker;
    }

    public function applicationRoot(): string
    {
        return $this->stagingRoot . ($this->useDocker ? DIRECTORY_SEPARATOR . 'www' : '');
    }

    public function targetPath(string $relativePath): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR);
    }

    public function relativeStagingPath(string $path): string
    {
        $prefix = $this->stagingRoot . DIRECTORY_SEPARATOR;
        if (!str_starts_with($path, $prefix)) {
            throw new \InvalidArgumentException("Le fichier {$path} n'appartient pas au staging.");
        }

        return ltrim(substr($path, strlen($prefix)), DIRECTORY_SEPARATOR);
    }
}
