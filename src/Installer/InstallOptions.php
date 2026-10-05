<?php

declare(strict_types=1);

namespace Julien\Installer;

final class InstallOptions
{
    public readonly bool $useDocker;
    public readonly bool $installDoctrine;
    public readonly bool $installAuth;
    public readonly bool $installApi;
    public readonly bool $installVision;
    public readonly bool $installSecure;

    public function __construct(
        bool $useDocker,
        bool $installDoctrine,
        bool $installAuth,
        bool $installApi,
        bool $installVision,
        bool $installSecure
    ) {
        if (($installAuth || $installApi) && !$installDoctrine) {
            $installDoctrine = true;
        }

        $this->useDocker = $useDocker;
        $this->installDoctrine = $installDoctrine;
        $this->installAuth = $installAuth;
        $this->installApi = $installApi;
        $this->installVision = $installVision;
        $this->installSecure = $installSecure;
    }

    /**
     * Crée une configuration depuis les réponses du questionnaire.
     * Auth et API activent automatiquement Doctrine, leur dépendance technique.
     */
    public static function fromChoices(
        bool $useDocker,
        bool $installDoctrine,
        bool $installAuth,
        bool $installApi,
        bool $installVision,
        bool $installSecure
    ): self {
        return new self(
            $useDocker,
            $installDoctrine,
            $installAuth,
            $installApi,
            $installVision,
            $installSecure
        );
    }
}
