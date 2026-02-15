<?php

declare(strict_types=1);

namespace App\services;

use App\repositories\AnexoRepo;
use App\repositories\ImportLogRepo;

class WorkflowService
{
    public function __construct(private AnexoRepo $anexoRepo, private ImportLogRepo $logRepo, private PgConsolidationService $pgService)
    {
    }

    public function status(int $projectId, string $tipo): array
    {
        $step1 = $this->anexoRepo->step1Status($projectId);
        $hasAnyAnexo = array_reduce($step1, fn (bool $carry, array $item): bool => $carry || $item['ok'], false);
        $pg = $this->pgService->load($projectId, $tipo);
        $step2Ok = $pg !== null;

        $flujoLog = $this->logRepo->latestByHoja($projectId, 'FLUJO_GENERADO');

        return [
            'step1' => ['ok' => $hasAnyAnexo, 'detail' => $step1],
            'step2' => ['ok' => $step2Ok, 'timestamp' => $pg['generated_at'] ?? null],
            'step3' => ['ok' => $flujoLog !== null, 'timestamp' => $flujoLog['CREADO_EN'] ?? null],
        ];
    }
}
