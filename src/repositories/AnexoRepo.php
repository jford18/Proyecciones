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

    public function listAnexos(array $filters, int $limit = 50, int $offset = 0): array
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

        $sql .= ' ORDER BY ID DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countAnexos(array $filters): int
    {
        $sql = 'SELECT COUNT(*) AS total FROM ANEXO_DETALLE WHERE 1=1';
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

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function countByProject(int $projectId): array
    {
        $stmtTotal = $this->pdo->prepare('SELECT COUNT(*) FROM ANEXO_DETALLE WHERE PROYECTO_ID = :project_id');
        $stmtTotal->execute(['project_id' => $projectId]);

        $stmtToday = $this->pdo->prepare('SELECT COUNT(*) FROM ANEXO_DETALLE WHERE PROYECTO_ID = :project_id AND DATE(FECHA) = CURDATE()');
        $stmtToday->execute(['project_id' => $projectId]);

        return [
            'today' => (int) $stmtToday->fetchColumn(),
            'total' => (int) $stmtTotal->fetchColumn(),
        ];
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
