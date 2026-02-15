<?php

declare(strict_types=1);

namespace App\services;

use App\repositories\FlujoRepo;

class FlujoGeneratorService
{
    public function __construct(private FlujoRepo $flujoRepo, private array $map)
    {
    }

    public function generate(int $projectId, string $tipo, array $pgData): int
    {
        $accounts = $this->map['pg_accounts'] ?? [];
        $count = 0;

        foreach ($accounts as $pgCuenta => $meta) {
            $lineaId = $this->flujoRepo->ensureLinea(
                $projectId,
                (string) ($meta['linea'] ?? $pgCuenta),
                (string) ($meta['seccion'] ?? 'OPERACION'),
                (int) ($meta['orden'] ?? 999)
            );

            $meses = $pgData['cuentas'][$pgCuenta] ?? array_fill(1, 12, 0.0);
            foreach ($meses as $mes => $valor) {
                $this->flujoRepo->upsertValor($projectId, $lineaId, $tipo, (int) $mes, (float) $valor);
                $count++;
            }
        }

        return $count;
    }
}
