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
        $stmt = $this->pdo->prepare('SELECT ID, PROYECTO_ID, ARCHIVO, HOJA, REGISTROS_INSERTADOS, MENSAJE, CREADO_EN FROM ANEXO_IMPORT_LOG WHERE PROYECTO_ID = :proyecto_id ORDER BY ID DESC LIMIT 1');
        $stmt->execute(['proyecto_id' => $proyectoId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
