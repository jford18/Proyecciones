<?php

declare(strict_types=1);

namespace App\repositories;

use PDO;

class FlujoRepo
{
    public function __construct(private PDO $pdo)
    {
    }

    public function ensureLinea(int $projectId, string $nombre, string $seccion, int $orden): int
    {
        $stmt = $this->pdo->prepare('SELECT ID FROM FLUJO_LINEA WHERE PROYECTO_ID = :project_id AND NOMBRE = :nombre LIMIT 1');
        $stmt->execute(['project_id' => $projectId, 'nombre' => $nombre]);
        $row = $stmt->fetch();
        if ($row) {
            return (int) $row['ID'];
        }

        $insert = $this->pdo->prepare('INSERT INTO FLUJO_LINEA (PROYECTO_ID, NOMBRE, SECCION, ORDEN) VALUES (:project_id, :nombre, :seccion, :orden_linea)');
        $insert->execute(['project_id' => $projectId, 'nombre' => $nombre, 'seccion' => $seccion, 'orden_linea' => $orden]);

        return (int) $this->pdo->lastInsertId();
    }

    public function upsertValor(int $projectId, int $lineaId, string $tipo, int $mes, float $valor): void
    {
        $update = $this->pdo->prepare('UPDATE FLUJO_VALOR SET VALOR = :valor WHERE PROYECTO_ID = :project_id AND FLUJO_LINEA_ID = :linea_id AND TIPO = :tipo AND MES = :mes');
        $update->execute([
            'valor' => $valor,
            'project_id' => $projectId,
            'linea_id' => $lineaId,
            'tipo' => $tipo,
            'mes' => $mes,
        ]);

        if ($update->rowCount() > 0) {
            return;
        }

        $insert = $this->pdo->prepare('INSERT INTO FLUJO_VALOR (PROYECTO_ID, FLUJO_LINEA_ID, TIPO, MES, VALOR) VALUES (:project_id, :linea_id, :tipo, :mes, :valor)');
        $insert->execute([
            'project_id' => $projectId,
            'linea_id' => $lineaId,
            'tipo' => $tipo,
            'mes' => $mes,
            'valor' => $valor,
        ]);
    }

    public function report(int $projectId, string $tipo): array
    {
        $sql = 'SELECT l.ID, l.NOMBRE, l.SECCION, l.ORDEN, v.MES, v.VALOR
                FROM FLUJO_LINEA l
                LEFT JOIN FLUJO_VALOR v ON v.FLUJO_LINEA_ID = l.ID AND v.PROYECTO_ID = l.PROYECTO_ID AND v.TIPO = :tipo
                WHERE l.PROYECTO_ID = :project_id
                ORDER BY l.ORDEN ASC, l.ID ASC, v.MES ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['project_id' => $projectId, 'tipo' => $tipo]);

        return $stmt->fetchAll() ?: [];
    }
}
