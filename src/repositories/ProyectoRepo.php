<?php

declare(strict_types=1);

namespace App\repositories;

use PDO;

class ProyectoRepo
{
    public function __construct(private PDO $pdo)
    {
    }

    public function listAll(): array
    {
        $stmt = $this->pdo->query('SELECT ID, NOMBRE, ANIO, ESTADO FROM PROYECTO ORDER BY ID');

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ID, NOMBRE, ANIO, ESTADO FROM PROYECTO WHERE ID = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $project = $stmt->fetch();

        return $project !== false ? $project : null;
    }

    public function createDefaultIfEmpty(): int
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM PROYECTO')->fetchColumn();
        if ($count > 0) {
            $firstId = (int) $this->pdo->query('SELECT ID FROM PROYECTO ORDER BY ID LIMIT 1')->fetchColumn();

            return $firstId;
        }

        $stmt = $this->pdo->prepare('INSERT INTO PROYECTO (NOMBRE, ANIO, ESTADO) VALUES (:nombre, :anio, :estado)');
        $stmt->execute([
            'nombre' => 'FLUJO CAJA PROYECTADO',
            'anio' => 2026,
            'estado' => 'ACTIVO',
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
