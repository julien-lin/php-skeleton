<?php

declare(strict_types=1);

namespace Julien\Tests;

use Julien\Installer\TemplateRepository;
use PHPUnit\Framework\TestCase;

final class TemplateRepositoryTest extends TestCase
{
    public function testReadsVersionedInstallerTemplate(): void
    {
        $repository = new TemplateRepository(dirname(__DIR__) . '/templates/installer');

        self::assertStringContainsString(
            '{{bootstrap_imports}}',
            $repository->read('environments/common/public/index.php')
        );
    }

    public function testRejectsUnsafeTemplatePaths(): void
    {
        $repository = new TemplateRepository(dirname(__DIR__) . '/templates/installer');

        $this->expectException(\InvalidArgumentException::class);
        $repository->read('../composer.json');
    }

    public function testReportsMissingTemplate(): void
    {
        $repository = new TemplateRepository(dirname(__DIR__) . '/templates/installer');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Template de l\'installateur introuvable');
        $repository->read('missing/template.php');
    }
}
