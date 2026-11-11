# Integración: importador de personal autorizado LOC (.xls)

Este paquete es un parche de integración basado en la estructura descrita del proyecto; no sustituye ni sobrescribe el repositorio completo. Integra primero en una rama o copia de seguridad.

## 1. Instalar lector de Excel antiguo

Desde `laravel-app`:

```powershell
composer require phpoffice/phpspreadsheet
```

PhpSpreadsheet lee `.xls` BIFF. Comprueba los requisitos de PHP/extensiones si Composer solicita alguna dependencia.

## 2. Copiar archivos

Copia ambas migraciones del paquete, el modelo, el servicio, el controlador y la vista de vista previa en sus rutas homónimas. La migración `2026_10_21_000004_expand_cuip_columns_to_100_characters` amplía las columnas CUIP después de crear las tablas históricas; no edites migraciones que ya se hayan ejecutado.

## 3. Integrar rutas

Abre `routes/web.php`. Sustituye las dos rutas estáticas de `/gestion` y `/gestion/historial` por las rutas de `routes/loc-gestion-routes.php`, conservando el middleware de autenticación/roles que ya tenga el proyecto. No registres rutas duplicadas con el mismo nombre.

## 4. Integrar tarjeta en el maquetado actual

En `resources/views/modules/gestion.blade.php`, reemplaza únicamente la tarjeta estática de “Personal autorizado LOC” por el contenido de `resources/views/modules/gestion-upload-card.blade.php` (puedes copiarlo en línea o usar `@include('modules.gestion-upload-card')`). Respeta las pestañas y componentes existentes.

La vista de historial actual debe mostrar `$batches`; si todavía es estática, agrega una tabla con `nombre_archivo`, `tipo_fuente`, `fecha_procesamiento`, `estado`, `total_leidas`, `total_aceptadas`, `total_rechazadas` y `total_actualizadas`, con paginación.

## 5. Ajustes del modelo existente

En `app/Models/LocPersona.php`, agrega `datos_loc` a `$fillable` y al cast como array. No reemplaces el resto de `$fillable` ni sus relaciones:

```php
// dentro de $fillable:
'datos_loc',

// dentro de casts():
'datos_loc' => 'array',
```

Verifica que `LocImportBatch::$fillable` incluya los campos usados por el controlador: `tipo_fuente`, `nombre_archivo`, `fecha_procesamiento`, `usuario_id`, `estado`, `total_leidas`, `total_aceptadas`, `total_rechazadas`, `total_actualizadas`, `resumen_errores`.

## 6. Migrar y probar

```powershell
php artisan migrate
php artisan route:list --name=gestion
php artisan test
```

No uses `migrate:fresh`: elimina tablas y datos.

Para archivos de más de 10,000 filas, revisa en `php.ini`/servidor `memory_limit`, `max_execution_time` y `upload_max_filesize`/`post_max_size` (el límite del controlador está en 100 MB). El lector procesa filas en bloques de 500 y guarda la vista previa en base de datos para no depender de la sesión.

## 7. Comportamiento y límites que deben verificarse con el archivo real

- La primera fila se considera encabezado.
- Se conservan encabezados de origen en `loc_personas.datos_loc`; los nombres duplicados se distinguen con sufijos (`__2`) y encabezados vacíos se guardan como `COLUMNA_SIN_NOMBRE_N`.
- Los campos canónicos actuales son los reconocidos por `loc_personas`: ID_PERSONAL, NOMBRE, RFC, CUIP, CURP, CATEGORIA, SUBDIRECCION, MUNICIPIO, REGION, AGRUPAMIENTO, CLAVE, CUERPO DE SEGURIDAD, OBSERVACIONES y ESTATUS. FECHA DE BAJA se convierte a fecha ISO.
- Se usa CUIP como llave de coincidencia. Una fila sin CUIP, sin nombre o con CUIP repetido dentro del mismo archivo se rechaza para evitar asignaciones ambiguas.
- El importador conserva CUIP normalizados de hasta 100 caracteres. La migración incremental amplía el almacenamiento a `VARCHAR(100)`; valores que superen esa capacidad se rechazan sin truncar el contenido original.
- El texto de ESTATUS no provoca una baja automática. Sólo una FECHA DE BAJA válida desactiva una persona.
- Las filas con error quedan en staging e historial; no se importan al confirmar.
- Antes de producción, se debe validar la equivalencia de las cabeceras exactas contra un `.xls` real. Este parche conserva todos los valores de origen, pero no crea columnas SQL independientes para cada evento histórico (inclusión, autorización, revalidación, cambio de adscripción o situación especial).

## Consideraciones para producción

El análisis se ejecuta durante la petición HTTP. Para ficheros mucho mayores, límites estrictos de ejecución o uso concurrente, conviene mover el análisis a una cola (`ShouldQueue`) y mostrar estado de procesamiento. La confirmación aplica escrituras por bloques transaccionales; cada bloque es atómico, no el lote completo.
