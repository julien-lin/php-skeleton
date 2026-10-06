<?php

declare(strict_types=1);

namespace Julien\Installer;

final class NpmRunner
{
    /**
     * @param \Closure(string): string $redact
     * @param \Closure(string): void $log
     */
    public function __construct(
        private readonly string $executable,
        private readonly bool $verbose,
        private readonly \Closure $redact,
        private readonly \Closure $log
    ) {
    }

    /**
     * @param array<int, string> $arguments
     * @return array{0: array<int, string>, 1: int}
     */
    public function run(string $workingDirectory, array $arguments): array
    {
        if (!is_dir($workingDirectory)) {
            throw new \RuntimeException("Répertoire de travail introuvable: {$workingDirectory}");
        }

        $command = array_merge([$this->executable], array_values($arguments));
        ($this->log)('Commande npm: ' . ($this->redact)(implode(' ', array_map(
            static fn(mixed $argument): string => escapeshellarg((string) $argument),
            $command
        ))));

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Impossible de démarrer npm.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);
        $output = array_values(array_filter(
            preg_split('/\R/', trim((string) $stdout . "\n" . (string) $stderr)) ?: [],
            static fn(string $line): bool => $line !== ''
        ));
        $output = array_map($this->redact, $output);

        if ($this->verbose && $output !== []) {
            foreach ($output as $line) {
                ($this->log)('  ' . $line);
            }
        }

        return [$output, $returnCode];
    }
}
