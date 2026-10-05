<?php

declare(strict_types=1);

namespace Julien\Tests;

use Julien\Installer\ComposerRunner;
use PHPUnit\Framework\TestCase;

final class ComposerRunnerTest extends TestCase
{
    public function testRunsStructuredCommandAndReturnsOutput(): void
    {
        $runner = new ComposerRunner(
            PHP_BINARY,
            false,
            static fn(string $text): string => $text,
            static function (string $message): void {
            }
        );

        [$output, $returnCode] = $runner->run(sys_get_temp_dir(), ['-r', 'echo "ok";']);

        self::assertSame(0, $returnCode);
        self::assertSame(['ok'], $output);
    }

    public function testRejectsMissingWorkingDirectory(): void
    {
        $runner = new ComposerRunner(
            PHP_BINARY,
            false,
            static fn(string $text): string => $text,
            static function (string $message): void {
            }
        );

        $this->expectException(\RuntimeException::class);
        $runner->run(sys_get_temp_dir() . '/php-skeleton-missing-' . bin2hex(random_bytes(4)), []);
    }
}
