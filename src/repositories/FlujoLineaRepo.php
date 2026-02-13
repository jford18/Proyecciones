<?php

declare(strict_types=1);

namespace App\repositories;

use PDO;

class FlujoLineaRepo
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM FLUJO_LINEA WHERE ID = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM FLUJO_LINEA WHERE NOMBRE = :nombre LIMIT 1');
        $stmt->execute(['nombre' => $name]);

        return $stmt->fetch() ?: null;
    }
}
