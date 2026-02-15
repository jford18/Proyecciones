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
        $sql = 'SELECT COUNT(*) FROM ANEXO_DETALLE WHERE 1=1';
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

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function countByProject(int $projectId): array
    {
        $stmtTotal = $this->pdo->prepare('SELECT COUNT(*) FROM ANEXO_DETALLE WHERE PROYECTO_ID = :project_id');
        $stmtTotal->execute(['project_id' => $projectId]);

        return [
            'total' => (int) $stmtTotal->fetchColumn(),
        ];
    }

    public function step1Status(int $projectId): array
    {
        $stmt = $this->pdo->prepare('SELECT TIPO_ANEXO, COUNT(*) as total, SUM(VALOR) as monto FROM ANEXO_DETALLE WHERE PROYECTO_ID = :project_id GROUP BY TIPO_ANEXO');
        $stmt->execute(['project_id' => $projectId]);
        $rows = $stmt->fetchAll() ?: [];

        $status = [
            'GASTOS' => ['ok' => false, 'total' => 0, 'monto' => 0.0],
            'NOMINA' => ['ok' => false, 'total' => 0, 'monto' => 0.0],
            'COBRANZA' => ['ok' => false, 'total' => 0, 'monto' => 0.0],
            'ACTIVOS' => ['ok' => false, 'total' => 0, 'monto' => 0.0],
        ];

        foreach ($rows as $row) {
            $tipo = (string) $row['TIPO_ANEXO'];
            if (!isset($status[$tipo])) {
                continue;
            }
            $status[$tipo] = [
                'ok' => ((int) $row['total']) > 0,
                'total' => (int) $row['total'],
                'monto' => (float) $row['monto'],
            ];
        }

        return $status;
    }

    public function aggregateForConsolidation(int $projectId, string $tipo): array
    {
        $stmt = $this->pdo->prepare('SELECT TIPO_ANEXO, MES, CODIGO, CONCEPTO, SUM(VALOR) as VALOR FROM ANEXO_DETALLE WHERE PROYECTO_ID = :project_id AND TIPO = :tipo GROUP BY TIPO_ANEXO, MES, CODIGO, CONCEPTO');
        $stmt->execute(['project_id' => $projectId, 'tipo' => $tipo]);

        return $stmt->fetchAll() ?: [];
    }
}
