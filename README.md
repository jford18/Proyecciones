# Importador de Anexos (PHP puro + MySQL)

Aplicación sin framework para importar hojas Excel de **GASTOS** y **NOMINA** hacia `ANEXO_DETALLE`, ahora con navegación por módulos y archivo/proyecto activo en sesión.

## Requisitos
- PHP 8.1+
- MySQL
- Composer

## Instalación
1. `composer install`
2. Configurar conexión en `src/config/config.php`.
3. `php -S localhost:8000 -t public`
4. Abrir `http://localhost:8000`.

## Navegación principal
- `/?r=dashboard`
- `/?r=upload`
- `/?r=files`
- `/?r=import-gastos`
- `/?r=import-nomina`
- `/?r=anexos`
- `/?r=config`

## Comportamiento clave
- **Proyecto activo** en `$_SESSION['active_project_id']`.
- **Archivo activo** en `$_SESSION['active_file']`.
- Subidas en `public/uploads/` y metadatos en `public/uploads/files.json`.
- Importación sin input manual de ruta, usando archivo activo.
- Confirmación modal y bloqueo del botón durante el proceso.

## Endpoints de acciones
- `POST /?r=upload` sube archivo y lo deja activo.
- `POST /?r=select-file` cambia archivo activo desde historial.
- `POST /?r=import-gastos` importa hoja GASTOS.
- `POST /?r=import-nomina` importa hoja NOMINA.
- `GET /?r=anexos` lista anexos con filtros y paginación.
