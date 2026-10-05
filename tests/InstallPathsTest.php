<?php

declare(strict_types=1);

namespace Julien\Tests;

use Julien\Installer\InstallPaths;
use PHPUnit\Framework\TestCase;

final class InstallPathsTest extends TestCase
{
    public function testApplicationRootAndTargetPathsAreCentralized(): void
    {
        $paths = new InstallPaths('/tmp/project/', '/tmp/staging/', true);

        self::assertSame('/tmp/staging/www', $paths->applicationRoot());
        self::assertSame('/tmp/project/composer.json', $paths->targetPath('composer.json'));
        self::assertSame('www/public/index.php', $paths->relativeStagingPath('/tmp/staging/www/public/index.php'));
    }

    public function testRelativeStagingPathRejectsFilesOutsideStaging(): void
    {
        $paths = new InstallPaths('/tmp/project', '/tmp/staging', false);

        $this->expectException(\InvalidArgumentException::class);
        $paths->relativeStagingPath('/tmp/other/composer.json');
    }
}
