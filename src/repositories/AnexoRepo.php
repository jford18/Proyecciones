<?php

declare(strict_types=1);

namespace App\repositories;

use PDO;

class AnexoRepo
{
    public function __construct(private PDO $pdo)
    {
    }

    public function insertAnexoDetalleBatch(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $sql = 'INSERT INTO ANEXO_DETALLE (
            PROYECTO_ID, TIPO_ANEXO, TIPO, FECHA, PERIODO, MES, CODIGO, CONCEPTO, DESCRIPCION,
            VALOR, ORIGEN_ARCHIVO, ORIGEN_HOJA, ORIGEN_FILA, FLUJO_LINEA_ID
        ) VALUES (
            :proyecto_id, :tipo_anexo, :tipo, :fecha, :periodo, :mes, :codigo, :concepto, :descripcion,
            :valor, :origen_archivo, :origen_hoja, :origen_fila, :flujo_linea_id
        )';

        $stmt = $this->pdo->prepare($sql);
        $inserted = 0;

        $this->pdo->beginTransaction();
        foreach ($rows as $row) {
            $stmt->execute($row);
            $inserted++;
        }
        $this->pdo->commit();

        return $inserted;
    }

    public function listAnexos(array $filters): array
    {
        $sql = 'SELECT ID, TIPO_ANEXO, TIPO, MES, PERIODO, CODIGO, CONCEPTO, DESCRIPCION, VALOR, ORIGEN_HOJA, ORIGEN_FILA
                FROM ANEXO_DETALLE WHERE 1=1';
        $params = [];

        if (!empty($filters['proyectoId'])) {
            $sql .= ' AND PROYECTO_ID = :proyecto_id';
            $params['proyecto_id'] = (int) $filters['proyectoId'];
        }
        if (!empty($filters['tipoAnexo'])) {
            $sql .= ' AND TIPO_ANEXO = :tipo_anexo';
            $params['tipo_anexo'] = $filters['tipoAnexo'];
        }
        if (!empty($filters['tipo'])) {
            $sql .= ' AND TIPO = :tipo';
            $params['tipo'] = $filters['tipo'];
        }
        if (!empty($filters['mes'])) {
            $sql .= ' AND MES = :mes';
            $params['mes'] = (int) $filters['mes'];
        }

        $sql .= ' ORDER BY ID DESC LIMIT 500';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function updateFlujoLineaId(int $anexoId, ?int $flujoLineaId): void
    {
        $stmt = $this->pdo->prepare('UPDATE ANEXO_DETALLE SET FLUJO_LINEA_ID = :flujo_linea_id WHERE ID = :id');
        $stmt->execute([
            'flujo_linea_id' => $flujoLineaId,
            'id' => $anexoId,
        ]);
    }
}
