<?php

declare(strict_types=1);

namespace App\models;

use PDO;

class Cliente
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        $sql = 'SELECT id, nombre_empresa, nombre_gerente, ruc, logo, estado, fecha_creacion, fecha_actualizacion FROM clientes ORDER BY fecha_creacion DESC, id DESC';
        $stmt = $this->pdo->query($sql);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function find(int $id): ?array
    {
        $sql = 'SELECT id, nombre_empresa, nombre_gerente, ruc, logo, estado FROM clientes WHERE id = :id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function existsRuc(string $ruc, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM clientes WHERE ruc = :ruc';
        $params = ['ruc' => $ruc];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(array $data): int
    {
        $sql = 'INSERT INTO clientes (nombre_empresa, nombre_gerente, ruc, logo, estado, fecha_creacion, fecha_actualizacion)
                VALUES (:nombre_empresa, :nombre_gerente, :ruc, :logo, :estado, NOW(), NOW())';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'nombre_empresa' => $data['nombre_empresa'],
            'nombre_gerente' => $data['nombre_gerente'],
            'ruc' => $data['ruc'],
            'logo' => $data['logo'],
            'estado' => $data['estado'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $sql = 'UPDATE clientes
                SET nombre_empresa = :nombre_empresa,
                    nombre_gerente = :nombre_gerente,
                    ruc = :ruc,
                    logo = :logo,
                    estado = :estado,
                    fecha_actualizacion = NOW()
                WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'nombre_empresa' => $data['nombre_empresa'],
            'nombre_gerente' => $data['nombre_gerente'],
            'ruc' => $data['ruc'],
            'logo' => $data['logo'],
            'estado' => $data['estado'],
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM clientes WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
