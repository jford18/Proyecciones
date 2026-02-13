<?php

declare(strict_types=1);

namespace App\services;

use PDO;

class AnexoMapeoService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function resolveFlujoLineaId(int $proyectoId, string $tipoAnexo, ?string $codigo, ?string $concepto): ?int
    {
        if ($codigo !== null && $codigo !== '') {
            $stmt = $this->pdo->prepare('SELECT FLUJO_LINEA_ID FROM ANEXO_MAPEO WHERE PROYECTO_ID = :proyecto_id AND TIPO_ANEXO = :tipo_anexo AND CODIGO = :codigo LIMIT 1');
            $stmt->execute([
                'proyecto_id' => $proyectoId,
                'tipo_anexo' => $tipoAnexo,
                'codigo' => $codigo,
            ]);
            $row = $stmt->fetch();
            if ($row && isset($row['FLUJO_LINEA_ID'])) {
                return (int) $row['FLUJO_LINEA_ID'];
            }
        }

        if ($concepto !== null && $concepto !== '') {
            $stmt = $this->pdo->prepare('SELECT FLUJO_LINEA_ID FROM ANEXO_MAPEO WHERE PROYECTO_ID = :proyecto_id AND TIPO_ANEXO = :tipo_anexo AND CONCEPTO = :concepto LIMIT 1');
            $stmt->execute([
                'proyecto_id' => $proyectoId,
                'tipo_anexo' => $tipoAnexo,
                'concepto' => $concepto,
            ]);
            $row = $stmt->fetch();
            if ($row && isset($row['FLUJO_LINEA_ID'])) {
                return (int) $row['FLUJO_LINEA_ID'];
            }
        }

        return null;
    }
}
