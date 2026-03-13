<?php

declare(strict_types=1);

namespace App\controllers;

use App\models\Cliente;
use RuntimeException;

class ClientesController
{
    public function __construct(
        private Cliente $clienteModel,
        private string $publicPath
    ) {
    }

    public function index(): array
    {
        return ['clientes' => $this->clienteModel->all()];
    }

    public function crear(array $post, array $files): array
    {
        $data = $this->validate($post, $files, null);
        $id = $this->clienteModel->create($data);

        return ['ok' => true, 'message' => 'Cliente creado correctamente.', 'id' => $id];
    }

    public function editar(int $id, array $post, array $files): array
    {
        $existing = $this->clienteModel->find($id);
        if (!$existing) {
            throw new RuntimeException('Cliente no encontrado.');
        }

        $data = $this->validate($post, $files, $id, (string) ($existing['logo'] ?? ''));
        $this->clienteModel->update($id, $data);

        return ['ok' => true, 'message' => 'Cliente actualizado correctamente.'];
    }

    public function eliminar(int $id): array
    {
        $existing = $this->clienteModel->find($id);
        if (!$existing) {
            throw new RuntimeException('Cliente no encontrado.');
        }

        $this->clienteModel->delete($id);

        return ['ok' => true, 'message' => 'Cliente eliminado correctamente.'];
    }

    private function validate(array $post, array $files, ?int $id = null, string $currentLogo = ''): array
    {
        $nombreEmpresa = trim((string) ($post['nombre_empresa'] ?? ''));
        $nombreGerente = trim((string) ($post['nombre_gerente'] ?? ''));
        $ruc = trim((string) ($post['ruc'] ?? ''));
        $estado = strtoupper(trim((string) ($post['estado'] ?? 'ACTIVO')));

        if ($nombreEmpresa === '') {
            throw new RuntimeException('El nombre de la empresa es obligatorio.');
        }

        if ($ruc === '') {
            throw new RuntimeException('El RUC es obligatorio.');
        }

        if (!preg_match('/^\d{13}$/', $ruc)) {
            throw new RuntimeException('El RUC debe contener exactamente 13 dígitos numéricos.');
        }

        if ($this->clienteModel->existsRuc($ruc, $id)) {
            throw new RuntimeException('El RUC ya se encuentra registrado.');
        }

        if (!in_array($estado, ['ACTIVO', 'INACTIVO'], true)) {
            $estado = 'ACTIVO';
        }

        $logoPath = $currentLogo;
        $logoFile = $files['logo'] ?? null;
        if (is_array($logoFile) && (($logoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
            $logoPath = $this->saveLogo($logoFile);
        }

        return [
            'nombre_empresa' => $nombreEmpresa,
            'nombre_gerente' => $nombreGerente,
            'ruc' => $ruc,
            'logo' => $logoPath,
            'estado' => $estado,
        ];
    }

    private function saveLogo(array $logo): string
    {
        $error = (int) ($logo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No se pudo cargar el logo.');
        }

        $size = (int) ($logo['size'] ?? 0);
        if ($size <= 0 || $size > (2 * 1024 * 1024)) {
            throw new RuntimeException('El logo debe pesar máximo 2MB.');
        }

        $tmpName = (string) ($logo['tmp_name'] ?? '');
        $mimeType = mime_content_type($tmpName) ?: '';
        $allowedByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
        if (!isset($allowedByMime[$mimeType])) {
            throw new RuntimeException('El logo debe ser JPG o PNG.');
        }

        $extension = strtolower((string) pathinfo((string) ($logo['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            throw new RuntimeException('El logo debe tener extensión JPG o PNG.');
        }

        $dir = rtrim($this->publicPath, '/') . '/uploads/clientes';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el directorio de subida.');
        }

        $finalExt = $allowedByMime[$mimeType] === 'jpg' ? 'jpg' : 'png';
        $filename = 'cliente_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $finalExt;
        $destination = $dir . '/' . $filename;

        if (!move_uploaded_file($tmpName, $destination)) {
            throw new RuntimeException('No se pudo guardar el logo.');
        }

        return '/uploads/clientes/' . $filename;
    }
}
