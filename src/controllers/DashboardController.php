<?php

declare(strict_types=1);

namespace App\controllers;

use App\services\WorkflowService;

class DashboardController
{
    public function __construct(private WorkflowService $workflowService)
    {
    }

    public function stats(int $projectId, string $tipo): array
    {
        return [
            'workflow' => $this->workflowService->status($projectId, $tipo),
        ];
    }
}
