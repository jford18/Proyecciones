<?php

declare(strict_types=1);

namespace App\services;

use App\repositories\AnexoRepo;

class PgConsolidationService
{
    public function __construct(private AnexoRepo $anexoRepo, private string $cacheDir, private array $map)
    {
    }

    public function consolidate(int $projectId, string $tipo): array
    {
        $raw = $this->anexoRepo->aggregateForConsolidation($projectId, $tipo);
        if ($raw === []) {
            throw new \RuntimeException('No existe data en ANEXO_DETALLE para consolidar.');
        }

        $consolidated = [];
        foreach ($raw as $row) {
            $tipoAnexo = (string) $row['TIPO_ANEXO'];
            $pgCuenta = $this->resolveCuenta($tipoAnexo, (string) ($row['CODIGO'] ?? ''), (string) ($row['CONCEPTO'] ?? ''));
            if ($pgCuenta === null) {
                continue;
            }

            $mes = (int) $row['MES'];
            if (!isset($consolidated[$pgCuenta])) {
                $consolidated[$pgCuenta] = array_fill(1, 12, 0.0);
            }
            $consolidated[$pgCuenta][$mes] += (float) $row['VALOR'];
        }

        if ($consolidated === []) {
            throw new \RuntimeException('No existe mapeo. Configure reglas en Configurar mapeo.');
        }

        $payload = [
            'project_id' => $projectId,
            'tipo' => $tipo,
            'generated_at' => date('Y-m-d H:i:s'),
            'cuentas' => $consolidated,
        ];

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }

        file_put_contents($this->filePath($projectId, $tipo), json_encode($payload, JSON_PRETTY_PRINT));

        return $payload;
    }

    public function load(int $projectId, string $tipo): ?array
    {
        $file = $this->filePath($projectId, $tipo);
        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function filePath(int $projectId, string $tipo): string
    {
        return rtrim($this->cacheDir, '/') . "/pg_{$projectId}_{$tipo}.json";
    }

    private function resolveCuenta(string $tipoAnexo, string $codigo, string $concepto): ?string
    {
        $source = $this->map[$tipoAnexo] ?? null;
        if (!is_array($source)) {
            return null;
        }

        if ($codigo !== '' && isset($source['codigo'][$codigo])) {
            return (string) $source['codigo'][$codigo];
        }

        $conceptoKey = mb_strtolower(trim($concepto));
        if ($conceptoKey !== '' && isset($source['concepto'][$conceptoKey])) {
            return (string) $source['concepto'][$conceptoKey];
        }

        return null;
    }
}
