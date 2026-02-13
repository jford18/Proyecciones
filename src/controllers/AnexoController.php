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

        return $this->anexoRepo->listAnexos($filters);
    }
}
