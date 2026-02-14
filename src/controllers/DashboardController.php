<?php

declare(strict_types=1);

namespace App\controllers;

use App\repositories\AnexoRepo;
use App\repositories\ImportLogRepo;

class DashboardController
{
    public function __construct(private ImportLogRepo $logRepo, private AnexoRepo $anexoRepo)
    {
    }

    public function stats(int $projectId): array
    {
        return [
            'lastImport' => $this->logRepo->latest($projectId),
            'counts' => $this->anexoRepo->countByProject($projectId),
        ];
    }
}
