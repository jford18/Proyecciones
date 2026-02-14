<?php

declare(strict_types=1);

namespace App\controllers;

use App\repositories\AnexoRepo;

class AnexoController
{
    public function __construct(private AnexoRepo $anexoRepo)
    {
    }

    public function list(array $query): array
    {
        $filters = [
            'proyectoId' => $query['proyectoId'] ?? null,
            'tipoAnexo' => $query['tipoAnexo'] ?? null,
            'tipo' => $query['tipo'] ?? null,
            'mes' => $query['mes'] ?? null,
        ];

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;
        $total = $this->anexoRepo->countAnexos($filters);

        return [
            'rows' => $this->anexoRepo->listAnexos($filters, $perPage, $offset),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
            'filters' => $filters,
        ];
    }
}
