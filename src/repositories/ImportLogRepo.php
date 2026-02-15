<?php

declare(strict_types=1);

namespace App\repositories;

use PDO;

class ImportLogRepo
{
    public function __construct(private PDO $pdo)
    {
    }

    public function insertLog(int $proyectoId, string $archivo, string $hoja, int $registrosInsertados, string $mensaje): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO ANEXO_IMPORT_LOG (PROYECTO_ID, ARCHIVO, HOJA, REGISTROS_INSERTADOS, MENSAJE) VALUES (:proyecto_id, :archivo, :hoja, :registros_insertados, :mensaje)');
        $stmt->execute([
            'proyecto_id' => $proyectoId,
            'archivo' => $archivo,
            'hoja' => $hoja,
            'registros_insertados' => $registrosInsertados,
            'mensaje' => $mensaje,
        ]);
    }

    public function latest(int $proyectoId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ANEXO_IMPORT_LOG WHERE PROYECTO_ID = :proyecto_id ORDER BY ID DESC LIMIT 1');
        $stmt->execute(['proyecto_id' => $proyectoId]);

        return $stmt->fetch() ?: null;
    }

    public function latestByHoja(int $proyectoId, string $hoja): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ANEXO_IMPORT_LOG WHERE PROYECTO_ID = :proyecto_id AND HOJA = :hoja ORDER BY ID DESC LIMIT 1');
        $stmt->execute(['proyecto_id' => $proyectoId, 'hoja' => $hoja]);

        return $stmt->fetch() ?: null;
    }

    public function listRecent(int $proyectoId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ANEXO_IMPORT_LOG WHERE PROYECTO_ID = :proyecto_id ORDER BY ID DESC LIMIT :limite');
        $stmt->bindValue(':proyecto_id', $proyectoId, PDO::PARAM_INT);
        $stmt->bindValue(':limite', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }
}
