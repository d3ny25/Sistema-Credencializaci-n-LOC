# Preparación de prueba controlada del importador LOC

## Estado de esta preparación

En la revisión de solo lectura del 2026-10-22, la conexión MySQL mostró pendiente únicamente `2026_10_22_000001_add_armamento_import_support.php`. La inspección de metadatos confirmó que faltan `loc_import_staging_rows.matricula`, `loc_import_staging_rows.id_armamento`, `loc_armas.id_armamento`, `loc_armas.datos_origen` y el índice único `loc_armas_matricula_unique`; por eso el formulario de armas permanece deshabilitado. No se ejecutaron migraciones, escrituras ni importaciones en esa conexión. La prueba de la migración se hizo por separado en un contenedor MariaDB temporal y aislado.

Los scripts de `tools/loc-import-test` crean un SQLite separado en `%TEMP%\sistema-loc-import-isolated`, reemplazan el directorio de almacenamiento y deshabilitan cualquier caché de configuración del proyecto. Antes de `migrate`, el guard PHP verifica que Laravel tenga efectivamente seleccionado SQLite, que la ruta de DB sea la esperada y que DB_URL esté vacío. Si falla una condición, detiene el proceso antes de migrar.

También preparan los directorios runtime de Laravel (`framework/cache`, `sessions`, `testing`, `views`, `logs` y `app/private`) bajo ese almacenamiento temporal. Esto es necesario para que las vistas, sesiones y logs de la aplicación no dependan del `storage` compartido del proyecto.

No se encontró ni se proporcionó una copia anonimizada del Excel institucional. No se cargó ningún libro real, por lo que su lectura, mapeo y vista previa aún no están validados. Los controles automáticos disponibles generan libros sintéticos y usan SQLite en memoria.

## Inicializar y revisar el esquema aislado

Desde PowerShell:

```powershell
Set-Location "C:\Users\Daniel\Documents\sistema-de-credencializacion-loc\laravel-app"
powershell -ExecutionPolicy Bypass -File .\tools\loc-import-test\Initialize-IsolatedTestDb.ps1
```

El inicializador sólo reutiliza una carpeta preexistente si contiene exclusivamente la base vacía esperada (por ejemplo, si una ejecución previa se detuvo antes de migrar); rechaza cualquier archivo adicional o base no vacía. Ejecuta las migraciones únicamente después de verificar aislamiento y después verifica columnas, índices y relación. La ruta de almacenamiento privado también se redirige bajo la carpeta temporal; no se comparte `storage/app/private` del proyecto.

Para volver a comprobar el estado, esquema y pruebas sintéticas:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\loc-import-test\Verify-IsolatedTestDb.ps1
```

La última verificación aislada terminó correctamente: las migraciones aparecen como ejecutadas en SQLite temporal, el guard confirmó columnas, índices y FK, y las 12 pruebas sintéticas del importador pasaron (105 aserciones). No se cargó el Excel institucional ni se confirmó un lote real.

El verificador ejecuta pruebas del importador que generan libros `.xls` sintéticos, incluidas carga/vista previa sin escritura de padrón, filas rechazadas, columnas originales, duplicados, formatos numéricos, fechas, recuperación parcial y 10,001 registros. Las pruebas PHPUnit corren con su SQLite `:memory:` de `phpunit.xml`; no reciben la conexión MySQL activa.

Detención:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\loc-import-test\Stop-IsolatedTestDb.ps1
```

Los scripts no dejan un servidor web activo. La base se conserva en `%TEMP%` para inspección, y no se borra automáticamente. Si se desea limpiar después de revisar, elimina únicamente el archivo exacto `...\sistema-loc-import-isolated\database\loc-import-test.sqlite` y la carpeta temporal específica tras confirmar que no contiene ningún archivo de usuario. No guardes la copia del Excel dentro de esa carpeta ni del repositorio.

## Prueba posterior con copia autorizada y anonimizada

1. Obtén autorización institucional para una copia anonimizada. Sustituye nombres, RFC, CURP, CUIP, identificadores, oficios y otros datos personales por valores sintéticos consistentes; conserva la fila de encabezados, orden y formatos de celdas necesarios para validar la lectura. No subas la copia al repositorio ni la adjuntes a incidencias.
2. Antes de probarla, confirma el responsable del mapeo y las reglas institucionales; no interpretes `ESTATUS`, autorizaciones, bajas o vigencias fuera de las reglas expresamente aprobadas.
3. La revisión manual por navegador no puede hacerse aún: el proyecto no tiene inicio de sesión real y la vista `/login` es demostrativa. No se debe crear una ruta o cuenta de acceso temporal para eludir esa protección. Primero debe estar disponible autenticación segura y aprobación de un usuario autorizado.
4. Tras la autenticación aprobada, levanta la aplicación en un entorno que use exclusivamente el SQLite temporal. Comprueba por Tinker que `config('database.default')` sea `sqlite`, `config('database.connections.sqlite.database')` corresponda al archivo bajo `%TEMP%\sistema-loc-import-isolated` y que `config('database.connections.mysql.database')` no se seleccione. Mantén `APP_CONFIG_CACHE` fuera del proyecto, como en los scripts.
5. Carga la copia en Gestión de información y detente en la vista previa. Verifica hoja/columnas, conteo de filas, CUIP ausentes/duplicados, encabezados repetidos, formatos y muestras. Compara el conteo de `loc_personas` antes y después; debe permanecer igual. Comprueba que valores originales estén en `loc_import_staging_rows.datos` y que no haya datos personales en logs.
6. No envíes la confirmación durante esta primera ejecución. Si la vista previa no coincide, conserva el lote de prueba, registra sólo identificadores técnicos y el tipo de discrepancia, y corrige/valida antes de repetir.
7. Para ensayar la confirmación, crea una segunda base temporal separada, aplica allí las migraciones, importa la misma copia anonimizada, revisa primero el lote y confirma sólo con aprobación. Luego compara conteos de staging, estado de aplicación, `loc_personas` y `loc_import_batches`; prueba que una segunda confirmación sea denegada.
8. Detén la aplicación y conserva o elimina únicamente los archivos temporales de prueba tras confirmar su contenido. Nunca apuntes estos scripts a `mysql`, a la base operativa ni a un archivo SQLite existente fuera de `%TEMP%`.

## Pendientes antes de producción

- Implementar autenticación real y aprobar el aprovisionamiento/permiso administrativo.
- Ejecutar la prueba con el Excel institucional autorizado y anonimizado; no está validado aún.
- Medir tiempo, memoria y límites del servidor/proxy con el tamaño real. La operación actualmente es síncrona; no hay cola.
- Autorizar por separado cualquier migración sobre la base operativa después de revisión y respaldo institucional. Esta preparación no la aplica.
