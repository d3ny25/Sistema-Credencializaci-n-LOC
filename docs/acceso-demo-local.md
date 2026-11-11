# Acceso de demostración local

El acceso de demostración es una identidad en memoria para navegación visual. No crea usuarios, no inicia una sesión persistente y no habilita acciones de escritura. `auth` y los Gates `can:*` siguen activos en las rutas protegidas.

La identidad sólo se crea cuando `APP_ENV=local` y `LOCAL_DEMO_ACCESS=true`. La petición debe ser `GET` o `HEAD`; las acciones de escritura responden 403. Si ya hay una sesión real, se respetan sus permisos sin sustituir al usuario autenticado.

El middleware rechaza expresamente la base MySQL/MariaDB `loc_credencializacion`. No se debe navegar en modo demo contra una base operativa, ya que algunas vistas consultan datos persistidos. Para recorrer Gestión y Administración, inicia el servidor con una base SQLite temporal y vacía:

```powershell
Set-Location "C:\Users\Daniel\Documents\sistema-de-credencializacion-loc\laravel-app"

$env:APP_ENV = "local"
$env:LOCAL_DEMO_ACCESS = "true"
$env:DB_CONNECTION = "sqlite"
$env:DB_DATABASE = Join-Path $env:TEMP ("sistema-loc-demo-" + [guid]::NewGuid().ToString("N") + ".sqlite")
$env:DB_URL = ""
$env:DB_FOREIGN_KEYS = "true"

New-Item -ItemType File -Path $env:DB_DATABASE | Out-Null

php artisan config:clear
php artisan tinker --execute="echo config('database.default'), PHP_EOL; echo config('database.connections.sqlite.database'), PHP_EOL;"
php artisan migrate:status
php artisan migrate --force
php artisan serve
```

Comprueba que Tinker indique `sqlite` y el archivo bajo `%TEMP%` antes de migrar. Esas migraciones inicializan sólo el SQLite temporal; no ejecutes esos comandos con otra terminal o configuración que apunte a MySQL/MariaDB. Los datos operativos no se copian a esa base, así que las pantallas mostrarán estados vacíos salvo catálogos o información creada posteriormente en ese entorno de prueba.

Para desactivar el modo, detén el servidor con `Ctrl+C` y en esa misma terminal elimina las variables temporales:

```powershell
Remove-Item Env:LOCAL_DEMO_ACCESS
Remove-Item Env:DB_CONNECTION
Remove-Item Env:DB_DATABASE
Remove-Item Env:DB_URL
Remove-Item Env:DB_FOREIGN_KEYS
Remove-Item Env:APP_ENV
```

También se puede dejar `LOCAL_DEMO_ACCESS=false`. No se recomienda habilitar la opción en `.env` de un entorno compartido. En `testing`, `staging` y `production` no se concede el acceso demo aunque la variable esté habilitada.

Esta modalidad sólo permite revisar pantallas. La carga/confirmación del importador LOC, cambios de dispositivos, guardado de plantillas y demás escrituras requieren una cuenta real autorizada y pruebas separadas en una base de datos aislada.

## Usar la base MySQL/MariaDB existente con una cuenta real

El middleware de demo no reemplaza ni modifica la autenticación de un usuario real: cuando hay sesión real, conserva `auth` y los Gates de cada operación. Para operar la base `loc_credencializacion`, configura `LOCAL_DEMO_ACCESS=false` (recomendado), verifica que el servidor DB esté disponible y accede por `/login` con correo y contraseña de una cuenta institucional autorizada. Para Gestión y Administración, esa cuenta debe tener `role=superadmin`; las operaciones de dispositivos y plantillas además requieren sus permisos específicos. La vista del editor de plantillas no implica permiso para guardar.

El comando `php artisan loc:crear-superusuario` permite crear una cuenta autorizada de forma interactiva. Comprueba conexión PDO MySQL/MariaDB, la base actual y las columnas `users.name`, `email`, `password` y `role`; aborta salvo que el destino sea exactamente `loc_credencializacion`. Solicita correo y nombre, pide la contraseña oculta dos veces, aplica validación robusta y exige escribir `CREAR SUPERUSUARIO EN loc_credencializacion`. El modelo aplica el hash de Laravel. Si el correo ya existe, no cambia el nombre ni la contraseña: sólo ofrece asignar el rol reconocido `superadmin` tras confirmación exacta por correo. Si ya es superadmin, no hace cambios. La operación real requiere autorización institucional y respaldo verificable.

## Migraciones LOC pendientes

Las migraciones revisadas son `2026_10_21_000002_add_datos_loc_to_loc_personas_and_create_staging_rows` y `2026_10_21_000003_add_loc_import_review_metadata`. Agregan `loc_personas.datos_loc` JSON; crean `loc_import_staging_rows` con FK a `loc_import_batches` (cascade al borrar lote), índices y clave única lote/fila; añaden metadatos/contadores/huellas al lote y el estado de aplicación indexado a staging. No borran ni reemplazan el padrón durante `up`. El `down` de la primera elimina `datos_loc` y staging y el `down` de la segunda elimina metadatos: no ejecutes rollback tras guardar información.

Los tipos JSON, columnas `unsignedBigInteger` de `foreignId`, índices y FK son compatibles con MySQL 5.7.8+ y MariaDB 10.2.7+ con motor InnoDB. La columna JSON `after('observaciones')` depende del soporte de alteración de columnas de esos motores. La consulta `php artisan migrate:status` más reciente no logró conectar al servidor configurado, por lo que no se confirmó la versión real ni el estado actual remoto. No se probó en un servidor MariaDB; las pruebas automatizadas usan SQLite.

Antes de cualquier migración:

1. Solicita/aprueba un respaldo completo y verificable de `loc_credencializacion`, y prueba restaurarlo a un entorno separado.
2. Confirma que MySQL/MariaDB está disponible, identifica su versión y ejecuta `php artisan migrate:status` para verificar que sólo estén pendientes esas dos migraciones.
3. Revisa el plan de cambios. El comando siguiente aplica todas las migraciones pendientes de la app sobre la conexión seleccionada; para el estado observado, crea columnas/tablas/índices de staging LOC arriba descritos. **No lo ejecutes hasta tener respaldo comprobado y aprobación explícita para cambiar el esquema operativo:**

   ```powershell
   php artisan migrate --force
   ```

4. Después consulta `php artisan migrate:status` y confirma las dos migraciones como `Ran`. No uses `migrate:fresh`, `db:wipe` ni rollbacks.

La importación también escribe en `loc_import_batches` y `loc_import_staging_rows` durante el análisis y, sólo después de confirmar, inserta/actualiza `loc_personas`. Ensaya primero en una copia anonimizada y una base de pruebas; respalda y obtén autorización antes de confirmar una importación sobre el padrón operativo.
