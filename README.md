<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Persistencia de la Fase 4

La aplicación conserva el patrón estándar de Laravel para persistencia. Para enlazar con MariaDB, copia `.env.example` a `.env` y ajusta los valores de conexión del entorno real:

```bash
cp .env.example .env
php artisan config:clear
```

Ejemplo de configuración esperada:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=loc_credencializacion
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

Luego ejecute:

```bash
php artisan migrate
php artisan test
```

En este entorno de trabajo no se validó un servidor MariaDB operativo, por lo que la base de prueba sigue ejecutándose con SQLite salvo que se configure un servicio MariaDB disponible.

## Gestión de dispositivos

El inventario registra únicamente los equipos identificados en el prototipo CPAF: Zebra ZXP Series 8, Canon EOS Rebel T6, Topaz SigLite LCD 1x5 y Crossmatch Verifier 300 LC 2.0. Para crear las tablas, ejecute `php artisan migrate`.

La migración agrega `users.role`, inicialmente `NULL` para todas las cuentas. Asigne el rol `superadmin` únicamente durante el aprovisionamiento autorizado de usuarios; este rol permite entrar a Administración y consultar, registrar, editar, diagnosticar y cambiar estados de dispositivos. La aplicación todavía no incluye inicio de sesión ni una interfaz para asignar roles, por lo que el rol debe establecerse mediante el proceso institucional de aprovisionamiento antes de habilitar esas funciones.

Los permisos específicos de dispositivos también pueden asignarse mediante listas de IDs en `.env`: `DEVICE_VIEWER_IDS`, `DEVICE_MANAGER_IDS`, `DEVICE_TESTER_IDS` y `DEVICE_ADMINISTRATOR_IDS`. Cada variable acepta IDs separados por comas. Esas listas no conceden acceso a la sección general de Administración; los valores vacíos deniegan el permiso correspondiente.

Laravel no accede desde el servidor al USB, a las carpetas locales de EOS Utility o del lector, al servicio SigWeb de la estación ni al controlador de impresión Zebra. La acción de diagnóstico registra explícitamente este límite y nunca comunica una conexión o impresión satisfactoria. La impresión actual y el editor de plantillas permanecen separados y sin cambios.

## Importación del padrón de personal LOC

La sección Gestión de información permite cargar un libro Excel binario `.xls`, revisar sus filas y confirmar explícitamente los cambios al padrón. Requiere `phpoffice/phpspreadsheet`, las migraciones aplicadas y acceso autorizado a Administración (`auth` + `can:access-administration`). El archivo se guarda con nombre aleatorio en el disco privado `local`, se calcula su SHA-256 y se elimina al terminar el análisis; los valores de origen quedan en staging para revisión y trazabilidad.

### Configuración y límites de ejecución

`LOC_IMPORT_MAX_KB` configura el límite deseado del archivo (predeterminado 81920 KB / 80 MiB); `LOC_IMPORT_READ_CHUNK` y `LOC_IMPORT_WRITE_CHUNK` controlan los bloques de lectura y escritura (5000 y 250 predeterminados). El bloque de lectura se limita a 5000 y fue medido con el XLS real de 34,855 filas: frente a 2500 redujo el tiempo total sin superar el límite CLI de 512 MB. Los valores inferiores siguen disponibles por configuración si el entorno tiene menos memoria, a costa de más reaperturas del libro. `public/.user.ini` configura PHP para admitir archivos de hasta 80 MB y solicitudes POST de 96 MB, manteniendo `memory_limit=512M` y configurando 300 segundos para los límites de tiempo. El `php.ini` activo debe tener límites equivalentes cuando se use `php artisan serve`, ya que el servidor integrado es PHP CLI y no aplica `.user.ini`. El límite efectivo de carga es el mínimo entre la configuración de la aplicación y `upload_max_filesize`/`post_max_size` de PHP. También configura el límite equivalente en el servidor web o proxy. El análisis LOC se procesa en segundo plano mediante la conexión de cola `loc_import`, que reutiliza la tabla `jobs`; `LOC_IMPORT_QUEUE_CONNECTION` puede cambiar la conexión y `LOC_IMPORT_QUEUE` el nombre de cola.

Inicia un trabajador separado y mantenlo activo durante las importaciones:

```powershell
php artisan queue:work loc_import --queue=loc-imports --timeout=900 --tries=1
```

La página de vista previa consulta el estado cada dos segundos y muestra el número real de filas procesadas, sin porcentaje estimado. Si el lote lleva un minuto pendiente, la página indica el comando del worker que debe verificarse; si pasan dos minutos sin actualizarse mientras procesa, deja de mostrar el spinner y señala que el trabajo aún no está confirmado como fallido. Tras varios errores de consulta se detiene el sondeo automático y queda un enlace para volver a consultar el lote. Si el trabajador no está activo, el lote permanece en cola. `jobs` debe existir en la base configurada. Además, antes de usar el importador deben estar aplicadas las migraciones LOC que crean `loc_import_staging_rows` y sus metadatos; verifica `php artisan migrate:status` y aplica cambios operativos sólo con autorización y respaldo válido. El análisis conserva las filas en staging para revisión, pero no modifica `loc_personas`; la confirmación continúa siendo explícita. El SHA-256 y un bloqueo por usuario impiden que solicitudes simultáneas creen lotes duplicados para el mismo archivo; reenvíos de lotes en análisis, revisión, confirmados o parcialmente aplicados abren el lote existente. Los lotes fallidos pueden reintentarse cargando el archivo de nuevo. Para libros grandes, revisa `memory_limit`, `max_execution_time` y los timeouts del servidor/proxy.

Para admitir libros de aproximadamente 55 MB, usa como mínimo `upload_max_filesize=80M`, `post_max_size=96M` (mayor que el límite individual), `max_input_time=300` y `max_execution_time=300`. No eleves `memory_limit` automáticamente: el importador de armas lee el XLS mediante `IReadFilter`, vuelve a cargar sólo bloques de hasta 5000 filas y limita su buffer de escritura a 250; mantiene el límite de memoria existente. PHP-FPM/CGI aplica `public/.user.ini` (puede tardar hasta `user_ini.cache_ttl`, normalmente 5 minutos). El servidor integrado `php artisan serve` no usa `.user.ini`; cambia el `php.ini` que carga el mismo ejecutable PHP y reinicia el servidor. Verifica los límites desde el proceso HTTP; algunos valores de tiempo pueden tener valores predeterminados propios de PHP CLI. Si hay Nginx, Apache, proxy o túnel delante, su límite de cuerpo también debe superar 55 MB; se recomienda al menos 96 MB.

Valores opcionales de `.env`:

```env
LOC_IMPORT_MAX_KB=81920
LOC_IMPORT_READ_CHUNK=5000
LOC_IMPORT_WRITE_CHUNK=250
LOC_IMPORT_QUEUE_CONNECTION=loc_import
LOC_IMPORT_QUEUE=loc-imports
```

### Identificación y revisión

La primera fila contiene encabezados. Se normalizan espacios externos y repetidos, mayúsculas y acentos, sin eliminar puntuación ni convertir nombres de encabezado distintos; `CUIP` y `NOMBRE` son obligatorios. Durante la misma lectura que crea staging, el importador compara por bloques los grupos de encabezados equivalentes. Si sus valores coinciden en todas las filas después de recortar espacios externos, conserva una columna y descarta las redundantes; si hay diferencias, conserva todas las columnas, marca como rechazadas las filas discrepantes y muestra un error de limpieza para su revisión. Los encabezados vacíos se informan como advertencia y sus columnas permanecen en el origen sin mapearse. El informe del lote registra encabezados afectados, columnas descartadas, filas discrepantes y errores. Los datos originales de las columnas conservadas se guardan en staging, incluyendo `ID_PERSONAL` (mapeado a `personal_id`, nunca al ID interno). CUIP, tras trim y mayúsculas, identifica la persona. Todas las filas con CUIP duplicado dentro del archivo quedan en conflicto y no se aplican. Los campos vacíos no borran los datos existentes. Las fechas de baja/alta se validan, pero el texto de estatus no se interpreta como autorización o baja; una fecha de baja reconocida desactiva la persona según la regla ya aplicada en el servicio.

Cada bloque registra en `storage/logs/laravel.log` su número, rango de filas, filas procesadas, tiempos de apertura/lectura/mapeo/escritura y uso/pico de memoria, sin valores de celdas. El resumen del lote incluye métricas totales de revisión y confirmación, filas por segundo y memoria. Las fallas de base de datos incluyen SQLSTATE, código del controlador y un mensaje técnico reducido a categorías conocidas; no se registran SQL ni bindings. El CUIP se normaliza con trim y mayúsculas y se conserva íntegro hasta 100 caracteres; los valores vacíos o mayores a ese límite no se almacenan en la columna indexada y quedan rechazados. La migración incremental `2026_10_21_000004_expand_cuip_columns_to_100_characters` amplía las columnas indexadas de staging, personas, vigencias y solicitudes; su reversión se detiene si detecta valores que excederían el límite anterior.

La lectura por bloques del XLS usa un filtro de PhpSpreadsheet, pero debe volver a abrir y parsear el contenedor por bloque. Se probó cargar el libro real completo de una vez y PHP agotó el límite existente de 512 MB; por eso se conservó la lectura filtrada, sin elevar el límite. `rangeToArray` y staging siguen acotados al bloque y el libro se desconecta en `finally`. Para el archivo real de 34,855 filas, con escritura de 250 y PHP CLI `memory_limit=512M`, la línea base aislada con bloques de 2,500 tardó 121.562 s, con 88.895 s en lectura/mapeo/escritura, 19.521 s en comparar/limpiar columnas duplicadas y pico de 230 MB asignados / 191.1 MB en uso. Tras evitar la relectura Eloquent y las actualizaciones individuales de cada fila para resolver encabezados duplicados, el mismo XLS, SQLite `:memory:` y tamaños tardó 98.379 s con 2,500 filas por bloque: 86.251 s en lectura/mapeo/escritura, 0.274 s en comparación/limpieza y 230 MB asignados / 189.9 MB en uso. Con bloques de 5,000 y la misma escritura de 250, terminó en 69.351 s: 57.528 s en lectura/mapeo/escritura, 0.234 s en comparación/limpieza y pico de 276 MB asignados / 234.6 MB en uso, equivalente a 502.6 filas/s. Esto es 43% menos tiempo que la línea base de 2,500; bajo la misma configuración de 5,000, la medición previa a optimizar la comparación fue 89.070 s / 276 MB asignados. En una confirmación sintética aislada posterior del mismo XLS, la revisión tomó 71.424 s y la confirmación 19.021 s (2.474 s de huella y 16.519 s de aplicación), insertando las 34,855 filas en `loc_personas` de SQLite `:memory:`; dos CUIP mayores a 20 se conservaron. No existía una medición separada anterior de confirmación. Las pruebas no elevaron el límite de 512 MB.

Si una etapa de análisis falla o el worker agota memoria, el lote queda `fallido`, no es confirmable y sus filas parciales de staging se conservan para diagnóstico. Antes de reintentar, identifica el lote por ID en Gestión > Historial y revisa estado, código/incidencia, filas reportadas y cantidad parcial de staging. Consulta sólo el ID conocido con:

```sql
SELECT b.id, b.estado, b.total_leidas,
       JSON_UNQUOTE(JSON_EXTRACT(b.resumen_errores, '$.diagnostico.error_code')) AS error_code,
       JSON_UNQUOTE(JSON_EXTRACT(b.resumen_errores, '$.diagnostico.incident_id')) AS incident_id,
       (SELECT COUNT(*) FROM loc_import_staging_rows s WHERE s.import_batch_id = b.id) AS filas_staging
FROM loc_import_batches b
WHERE b.id = <ID_DEL_LOTE>;
```

No cambies manualmente el estado a `en_revision` ni relances el mismo lote: una carga parcial no tiene un libro disponible para una reanudación segura. `stageWorkbook()` no borra ni reutiliza filas existentes; la restricción única `(import_batch_id, numero_fila)` evita duplicados, pero un reintento manual sobre el mismo ID fallará con conflicto. El flujo normal de carga excluye lotes `fallido` al buscar un lote existente y crea otro ID al cargar de nuevo; staging del lote anterior queda retenido para auditoría. Una vez corregida la causa, vuelve a cargar el archivo original. La tabla definitiva `loc_personas` no cambia durante la preparación.

La vista de revisión muestra hoja/columnas, filas leídas, nuevas, por actualizar, sin cambios, rechazadas/conflictivas y paginación de filas. La confirmación compara una huella SHA-256 de los metadatos del lote y sus filas de revisión con la huella guardada antes de aplicar. Se revalida CUIP contra el padrón en bloques y cada bloque se confirma en su propia transacción. La segunda confirmación de un lote completado se deniega.

Si falla un bloque, los bloques previos quedan confirmados, las filas completadas conservan su estado y el lote pasa a `fallido_parcial`. El administrador puede reanudarlo desde la vista previa: se omiten las filas ya aplicadas y se revalidan las pendientes. Si cambia la revisión, su huella no coincide y el lote no se aplica. No hay borrado masivo ni reversión automática de los bloques ya confirmados; antes de reanudar, revisa los conteos y el historial del lote.

El historial consulta `loc_import_batches` con filtros reales de estado, fuente y fecha. No se registran filas completas en logs: los eventos de error sólo guardan el ID del lote y la clase de excepción. Valida el mapeo con el archivo LOC institucional en un entorno de prueba antes de usar datos operativos. El parser procesa el primer worksheet; no se ha asumido cuál hoja debe ser autoritativa para cualquier variante del archivo.

### Preparar un entorno limpio

1. Configura una base de prueba vacía con la conexión ya definida para el entorno; no uses `migrate:fresh` en una base existente.
2. Revisa las migraciones pendientes con `php artisan migrate:status`.
3. Ejecuta `php artisan migrate` únicamente en la base de prueba autorizada. Las migraciones `2026_10_21_000002` y `2026_10_21_000003` agregan staging/metadatos; el rollback de prueba debe hacerse allí con `php artisan migrate:rollback --step=2`.
4. Habilita el inicio de sesión real y aprovisiona un `superadmin` autorizado antes de habilitar el uso operativo. El acceso demo local, si está activado, es de solo lectura y no puede cargar ni confirmar importaciones.
5. Carga un `.xls` sintético o institucional aprobado, revisa todas las filas, confirma una sola vez y verifica los conteos persistidos en el historial.

### Migración de longitud CUIP

La conexión configurada durante la inspección fue MariaDB 11.4.13, con `utf8mb4_unicode_ci`, tablas InnoDB `Dynamic`, índice único de `loc_personas.cuip`, índice compuesto `(cuip, estatus)` y los índices de consulta en staging/vigencias/solicitudes. Con CUIP de 100 y `estatus` de 80 caracteres, el índice compuesto requiere como máximo 720 bytes en `utf8mb4`; no se agregan ni eliminan índices. La inspección de solo lectura del lote de revisión encontró dos valores CUIP de 22 caracteres conservados en `datos` JSON, mientras que las columnas indexadas tenían límite de 20.

En la inspección de solo lectura del 22-oct-2026, la migración `2026_10_21_000004_expand_cuip_columns_to_100_characters` ya aparecía registrada (lote 4) y las cuatro columnas eran `varchar(100)` en la base configurada; no se ejecutó ninguna migración durante esa revisión. En otro entorno, antes de aplicar el cambio, confirma conexión/versión, identifica y verifica la integridad del respaldo y programa una ventana controlada. Si `migrate:status` indica que sigue pendiente, ejecuta desde `laravel-app`:

```powershell
php artisan migrate:status
php artisan migrate --path=database/migrations/2026_10_21_000004_expand_cuip_columns_to_100_characters.php --force
php artisan migrate:status
php artisan queue:restart
```

Verifica después con `information_schema.COLUMNS` que las cuatro columnas CUIP sean `varchar(100)`. El rollback sólo es seguro mientras no haya CUIP de más de 20 caracteres en ninguna de esas columnas; la migración lo comprueba y se niega a truncar valores.

```sql
SELECT TABLE_NAME, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'loc_import_staging_rows',
      'loc_personas',
      'loc_vigencias_credenciales',
      'solicitud_elementos'
  )
  AND COLUMN_NAME = 'cuip'
ORDER BY TABLE_NAME;
```

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Importación de armamento LOC

La fuente `armamento_loc` utiliza las tablas existentes `loc_import_batches`, `loc_import_staging_rows` y `loc_armas`. Una matrícula disponible es la clave de identidad; el marcador exacto `S/M`, después de recortar espacios y sin distinguir mayúsculas, se normaliza a `NULL` y requiere `ID_ARMAMENTO` para identificar el registro. El valor original de la celda se conserva en el JSON de origen. Otros valores como `-` y `N/A` siguen siendo matrículas literales. `ID_ARMAMENTO` se guarda como referencia externa y nunca se utiliza para unir ni reemplazar armas. Se conservan todas las columnas originales en JSON, incluidas las columnas auxiliares y las fechas que no tienen una correspondencia inequívoca con columnas dedicadas.

El mapeo dedicado cubre `ID_ARMAMENTO`, `MATRICULA`, tipo, clase, marca, calibre, modelo, RFA, resguardo, propiedad, agrupamiento, región, municipio, `STATUS`, situación y observaciones. `STATUS` se almacena en `estatus`; no se interpreta como `activo` ni cambia el valor predeterminado existente de `activo`. Las matrículas `NULL` pueden repetirse; las matrículas reales repetidas no. Un `ID_ARMAMENTO` repetido dentro del lote o ya presente en inventario queda en conflicto, aun si la matrícula coincide; nunca se actualizan automáticamente los registros existentes. Los registros sin ID externo pueden clasificarse como sin cambios si coinciden íntegramente. Cualquier fila vacía, duplicada o conflictiva bloquea la confirmación de todo el lote.

El perfilado local de `Gestion de informacion/ARMAS.xls` identificó una hoja `Sheet0`, 55,765 filas de datos y 66 columnas. Los valores de `ID_ARMAMENTO` y `MATRICULA` están almacenados como texto; se detectaron 472 matrículas con cero inicial y una matrícula repetida en dos filas. No se imprimieron valores de filas. Por ello, la migración usa una comparación binaria sensible a mayúsculas para matrícula, conserva ceros iniciales y añade una restricción única; ambas filas duplicadas se mostrarán como conflicto y no se podrán confirmar hasta corregir la fuente.

La migración incremental `2026_10_22_000001_add_armamento_import_support.php` requiere las tablas base `loc_armas`, `loc_import_batches` y `loc_import_staging_rows`, así como las migraciones previas de staging/revisión. Se verificó aplicándola junto con las migraciones previas en un contenedor MariaDB 11.4 aislado, sin volumen persistente; se comprobaron las colaciones binarias, el índice único por matrícula y el rechazo de una matrícula exacta repetida. Esa prueba no consultó ni modificó la base operativa, no usó el Excel institucional ni confirmó un lote real.

La migración agrega `id_armamento` como referencia externa y `datos_origen` a `loc_armas`, añade matrícula/ID externo indexados al staging y sustituye el índice no único de matrícula por uno único sensible a mayúsculas. En MySQL/MariaDB la validación previa agrupa con la misma colación binaria que tendrá la restricción única y detiene la migración si ya hay matrículas duplicadas. La vista y el endpoint de carga requieren las columnas y ese índice único; una migración incompleta no habilita la importación.

Antes de aplicar cambios a cualquier base institucional todavía se requiere autorización explícita, respaldo y revisión de duplicados existentes con el responsable del inventario. No se aplicó esta migración a la base operativa. Su reversión se bloquea si ya hay lotes de armas o registros con los nuevos campos; no debe usarse como mecanismo de prueba ni elimina armas o lotes.

Para analizar un `.xls` de armamento de aproximadamente 55 MB, `LOC_IMPORT_MAX_KB` debe ser al menos `81920` (predeterminado 80 MiB); PHP debe permitir `upload_max_filesize=80M` y `post_max_size=96M`. Si se usa `php artisan serve`, configura el `php.ini` del ejecutable que inicia Artisan y reinicia ese servidor, pues PHP CLI no aplica `public/.user.ini`. El análisis usa un filtro de lectura PhpSpreadsheet por bloques de hasta 5000 filas y buffers de escritura limitados a 250; no eleva `memory_limit` automáticamente. El análisis sólo escribe lote/staging para revisión; la aplicación a `loc_armas` sigue requiriendo confirmación explícita.

La cola se separa de la de personal para no entregar la nueva clase de trabajo al worker persistente de personal. En un entorno de prueba con el esquema actualizado, iniciar un worker independiente:

```powershell
php artisan queue:work loc_weapons_import --queue=loc-weapons-imports --timeout=3600 --tries=1
```

Antes de iniciar el worker en Windows, verifica que no haya otro proceso dedicado y que ningún lote de armamento esté en `procesando`:

```powershell
Get-CimInstance Win32_Process |
    Where-Object { $_.CommandLine -match 'artisan queue:(work|listen) loc_weapons_import' } |
    Select-Object ProcessId, CommandLine
php artisan tinker --execute="dump(DB::table('loc_import_batches')->where('tipo_fuente', 'armamento_loc')->where('estado', 'procesando')->pluck('id'));"
php artisan tinker --execute="dump(DB::table('jobs')->where('queue', 'loc-weapons-imports')->whereNotNull('reserved_at')->count());"
```

Si alguna comprobación muestra un worker, un lote `procesando` o un trabajo reservado, no inicies otro worker; identifica y resuelve primero el trabajo activo. Inicia sólo una instancia supervisada del comando anterior. `--memory` en `queue:work` sólo hace que Laravel recicle el worker después de un trabajo; no limita el pico durante la lectura. El worker conserva el límite PHP efectivo y el job se protege además con un cambio transaccional `pendiente` → `procesando` para evitar que un segundo job confirme el mismo análisis.

Configuración opcional:

```env
LOC_WEAPONS_IMPORT_READ_CHUNK=5000
LOC_WEAPONS_IMPORT_WRITE_CHUNK=100
LOC_WEAPONS_IMPORT_QUEUE_CONNECTION=loc_weapons_import
LOC_WEAPONS_IMPORT_QUEUE=loc-weapons-imports
LOC_WEAPONS_IMPORT_RETRY_AFTER=3900
```

Para solicitar un reanálisis, marca explícitamente la casilla del formulario y vuelve a seleccionar el archivo exacto. El endpoint comprueba que el hash pertenezca a un lote anterior del mismo usuario, crea un lote nuevo y registra en su resumen el lote de origen, el usuario y una clave de solicitud. No cambia lotes anteriores, staging ni el inventario. La clave del formulario hace idempotentes los reenvíos; la transacción bloquea la fila de origen más antigua del hash/usuario y vuelve a leer los lotes después de adquirir el bloqueo. Esto serializa también claves distintas en MySQL/MariaDB y evita un segundo lote mientras el primero esté pendiente/procesándose. El job usa `afterCommit()` además de `after_commit=true` en la conexión para no quedar visible a un worker antes de confirmar la transacción del lote. La autorización administrativa y el modo demo de sólo lectura siguen aplicando.

### Pruebas de importación de armas

Los tests existentes que verifican las reglas y el comportamiento del importador están en [`tests/Feature/LocWeaponsImportTest.php`](./tests/Feature/LocWeaponsImportTest.php). Ejecuta la suite con:

```powershell
php artisan test tests\Feature\LocWeaponsImportTest.php
```

La suite comprueba:

- **Carga y acceso:** límite de tamaño del archivo, autenticación administrativa, creación de lotes, despacho del job a la cola dedicada y paginación/filtros de la vista previa.
- **Transacciones y reanálisis:** el job no se hace visible antes del commit; los reenvíos son idempotentes, generan como máximo un lote nuevo por solicitud y conservan los lotes anteriores y sus metadatos.
- **Lectura y rendimiento aislado:** equivalencia entre tamaños de bloque y comparación de análisis sintético completo. La prueba de benchmark por bloques requiere activar expresamente `LOC_WEAPONS_XLS_BENCHMARK=1` y usa un libro sintético.
- **Identidad y preservación de origen:** se conservan ceros iniciales y columnas originales; `S/M` (incluyendo variantes de mayúsculas y espacios) se normaliza como matrícula no disponible sin perder el texto fuente; `ID_ARMAMENTO` permanece como referencia externa y no sustituye la matrícula.
- **Validación y conflictos:** se rechazan matrículas ausentes o duplicadas; se detectan conflictos de matrícula e ID externo, incluso el ID `"0"`; los conflictos no sobrescriben armas existentes y bloquean la confirmación.
- **Clasificación y consultas:** la búsqueda por matrícula/ID y la reducción de consultas/progreso conservan la clasificación de filas.
- **Confirmación segura:** sólo se insertan armas nuevas; registros sin cambios pueden confirmarse sin inserción; cambios en el inventario después de la revisión detienen la confirmación.
- **Esquema y migración:** se verifica el índice único sensible a mayúsculas, las columnas de referencia/origen y que la carga permanezca deshabilitada si falta el esquema requerido; el rollback se rehúsa cuando hay lotes de armamento.
- **Errores parciales y recuperación:** una falla de worker conserva el staging y bloquea la confirmación; una excepción de bloque registra la ubicación exacta del progreso sin perder filas ya almacenadas.

Estas pruebas usan el entorno configurado para tests y fixtures sintéticos; no ejecutan la importación de `Gestion de informacion\ARMAS.xls` ni representan autorización para operar sobre una base institucional.

La prueba automatizada cubre reenvíos e idempotencia en SQLite y la visibilidad del job antes/después del commit. Además, el 22/10/2026 se ejecutaron dos pares de solicitudes simultáneas al controlador mediante procesos PHP separados contra MariaDB 11.4 desechable en `127.0.0.1:3310`, con el caché local sustituido por `array` para que la exclusión dependiera del bloqueo SQL: el par con la misma clave creó un lote y un job; tras cerrar ese lote de prueba, el par con claves distintas creó un solo lote adicional y un job. Ambos lotes quedaron pendientes, sin worker; la tabla de armas permaneció vacía. Esta prueba no se conectó a la base operativa. En esa misma MariaDB se comprobó el despacho posterior al commit: jobs en cola antes/dentro de la transacción `0`, después del commit `1`, sin reserva activa.

Para comparar el lector sin escribir en ninguna base, la prueba automatizada crea un XLS sintético de 360 filas y 66 columnas:

```powershell
$env:LOC_WEAPONS_XLS_BENCHMARK = '1'
php artisan test tests\Feature\LocWeaponsImportTest.php --filter=xls_chunk_sizes
Remove-Item Env:\LOC_WEAPONS_XLS_BENCHMARK
```

El fixture sintético no predice fielmente el pico del libro real: la estructura interna y estilos del `.xls` influyen en PhpSpreadsheet. Para medir el archivo real sin tocar el original, se hizo una copia local con ACL restringida, se verificó su SHA-256 y se procesó contra MariaDB 11.4 desechable en tmpfs, usando PHP 8.4.0 y `memory_limit=512M`. Un análisis completo con bloques de 500 duró 2.168,73 segundos (36 min 8,73 s), leyó 55.765 filas, dejó el lote de prueba en `en_revision`, clasificó las 55.765 como nuevas, cero conflictos/errores y cero actualizaciones; `loc_armas` siguió vacío. La memoria pico PHP fue 280 MiB y el pico de working set muestreado del proceso 296,5 MiB. Las filas de origen 23989 y 36113 quedaron como candidatas nuevas sin errores en este inventario de prueba vacío.

También se hicieron muestras del XLS real con tres bloques por tamaño (no análisis completos): 100 filas, 300 filas en 40,05 s y pico PHP 254 MiB; 250 filas, 750 filas en 40,43 s y 260 MiB; 500 filas, 1.500 filas en 41,13 s y 270 MiB. Los picos de working set muestreados fueron 280,6/265,7/262,2 MiB, respectivamente. Debido al tiempo observado del análisis completo y al coste de volver a abrir el libro por bloque, no se ejecutaron análisis completos con bloques 100 y 250; esos tiempos muestrales no se extrapolan a duración total ni prueban que esos tamaños sean mejores. No se cambió el valor predeterminado de 5000 filas ni se elevó `memory_limit`. Se requiere una medición de corrida completa si se propone cambiarlo.

Cada bloque del importador registra apertura, lectura, mapeo/validación, comparación con el inventario, escritura, duración y memoria, sin registrar celdas ni identificadores. El servicio vuelve a abrir el XLS en cada bloque. La corrida completa de 500 incluyó staging, clasificación y fingerprint en MariaDB aislado; no incluyó confirmación. La confirmación guarda métricas separadas y sólo inserta filas nuevas; una huella de revisión detecta cambios en staging.

En el lote de revisión #8, 55.765 filas se analizaron en 2.719,465 segundos y 112 bloques, con 290 MiB de pico PHP. La suma de `open_seconds` fue 2.576,437 s (94,741 % del tiempo total); lectura, mapeo/validación, comparación y staging sumaron 59,989 s (2,206 %). Los 83,039 s restantes (3,053 %) incluyen clasificación final, fingerprint, persistencia del progreso y otras operaciones; el log no desglosa todas ellas. No hay un conteo histórico de consultas SQL para esa corrida.

El comparador ahora reúne en una consulta indexada los candidatos por matrícula o `id_armamento` para cada buffer de escritura, en lugar de hacer una consulta separada por identidad. Se conserva la igualdad SQL, la prioridad de matrícula y el conflicto por colisión de ID externo. El progreso se persiste una vez al completar cada bloque, con sus filas y conteo acumulado; ante una excepción, se intenta guardar la fase y el rango exactos del bloque fallido antes de propagar el error. Así se evita una escritura de progreso adicional por bloque exitoso y el último checkpoint corresponde al trabajo completado. Una prueba SQLite en memoria con cuatro filas y dos buffers verifica las consultas al inventario, las actualizaciones de progreso/lote y las mismas acciones/conflictos esperados. La corrida #8 no registró eventos SQL, por lo que la reducción histórica exacta no puede contarse.

La prueba `test_full_synthetic_analysis_can_be_compared_across_read_chunk_sizes` mide el análisis completo, incluido staging, sobre un XLS sintético de 360 filas en SQLite `:memory:`. Para repetir la medición en PowerShell:

```powershell
$env:LOC_WEAPONS_XLS_BENCHMARK = '1'
php artisan test tests\Feature\LocWeaponsImportTest.php --filter=full_synthetic_analysis_can_be_compared_across_read_chunk_sizes
Remove-Item Env:\LOC_WEAPONS_XLS_BENCHMARK
```

Una ejecución local obtuvo 360 candidatas, cero conflictos/errores y 10 MiB de pico para cada tamaño; los tiempos totales fueron 0,139 s (100), 0,084 s (250) y 0,081 s (500). No es una comparación antes/después del cambio ni representa el XLS real: sólo confirma resultados equivalentes y permite comparar tamaños en un fixture aislado, sin tocar inventario operativo.

Como lectura aislada del mismo XLS (sin staging ni acceso a la base), las mismas primeras 2.000 filas se leyeron con `memory_limit=512M`: bloques de 500, 1.000 y 2.000 tardaron respectivamente 92,815 s (cuatro aperturas, pico 270 MiB), 54,487 s (dos aperturas, pico 286 MiB) y 38,841 s (una apertura, pico 224 MiB). Son muestras de lector, no análisis completos ni una medición posterior de las optimizaciones SQL/progreso. No se cambió ningún tamaño predeterminado; para cambiarlo se requiere validar memoria y tiempo en una corrida aislada completa.

La instancia MariaDB 11.4 de ensayo usó el puerto loopback `3310`, base `loc_credencializacion_test` y almacenamiento tmpfs sin volumen persistente; el runtime de los ensayos comprobó explícitamente host, puerto, nombre de base y versión/límite de PHP. El puerto operativo configurado `3308` no se usó durante estas pruebas. El ensayo no confirmó lotes ni insertó armas; no constituye autorización para procesar ARMAS.XLS en la base operativa.

### Benchmark aislado del lector XLS real

La herramienta `tools/benchmarks/benchmark_armas_xls_reader.php` mide exclusivamente la lectura del archivo con PhpSpreadsheet. Se ejecuta como PHP CLI independiente: carga únicamente el autoloader de Composer, no inicia Laravel, no conecta a ninguna base de datos y no crea lotes, jobs ni staging. Implementa un filtro de lectura equivalente al `LocWeaponsXlsChunkReadFilter` del importador y no imprime contenidos de celdas. Requiere las dependencias Composer instaladas (`phpoffice/phpspreadsheet` 5.10.0) y PHP 8.4 en las condiciones de esta medición.

Desde la raíz `laravel-app`, verificar primero el hash mostrado por PowerShell y ejecutar las configuraciones en serie:

```powershell
$file = 'Gestion de informacion\ARMAS.xls'
Get-Item -LiteralPath $file | Select-Object FullName, Length
Get-FileHash -LiteralPath $file -Algorithm SHA256
php tools\benchmarks\benchmark_armas_xls_reader.php --file="$file" --expected-sha256=7BBB8FEFDA3FD0591D53F036099FA56E7CF98C22FEF07E10A673B97CA2A0B7F8 --rows=2000 --chunks=500,1000,2000,5000 --max-peak-mb=400
```

El script detiene la ejecución si el hash no coincide, si los datos de las mismas filas difieren entre configuraciones, si cambia el archivo o si el pico asignado supera el umbral. La salida etiqueta el modo como `full` cuando se solicitan todas las filas de datos y guarda las configuraciones terminadas y el progreso de la configuración interrumpida. El tamaño predeterminado configurado en producción (5000 filas) se incluye como cuarta configuración en la muestra anterior. No se debe ejecutar en paralelo.

El 23/10/2026 el archivo exacto fue `Gestion de informacion\ARMAS.xls`, 55.076.352 bytes, SHA-256 `7BBB8FEFDA3FD0591D53F036099FA56E7CF98C22FEF07E10A673B97CA2A0B7F8`. El libro informa 55.766 filas incluyendo encabezado (55.765 filas de datos). En PHP 8.4.0 con `memory_limit=512M`, se midió una muestra de las mismas 2.000 filas (filas 2-2001), no una pasada completa:

| Bloque | Bloques / cargas XLS | Filas no vacías | Inicialización del lector | Apertura/carga acumulada | Lectura de celdas | Tiempo total | Pico PHP usado / asignado |
|---:|---:|---:|---:|---:|---:|---:|---:|
| 500 | 4 | 2.000 | 0,000096 s | 110,638 s | 0,840 s | 111,699 s | 165,1 / 272 MiB |
| 1.000 | 2 | 2.000 | 0,000040 s | 57,517 s | 0,835 s | 58,531 s | 184,1 / 288 MiB |
| 2.000 | 1 | 2.000 | 0,000020 s | 30,499 s | 0,847 s | 31,453 s | 222,2 / 288 MiB |
| 5.000 (predeterminado) | 1 | 2.000 | 0,000019 s | 30,311 s | 0,889 s | 31,335 s | 222,2 / 324 MiB |

Las cuatro configuraciones devolvieron exactamente 2.000 filas no vacías, cero vacías, sin errores, y el mismo SHA-256 de huella de celdas (`df95c1f6f02c7ba8d4545cb2907707402df5eb0b43abd811cb5cf6e6813e90db`). El tiempo de metadata con `listWorksheetInfo` fue 21,929 s y se reporta por separado, excluido de los tiempos por configuración. Las diferencias entre el total y los tiempos de apertura/lectura incluyen el conteo/huella de filas, cierre de libros y sobrecarga del bucle.

La carga del archivo dominó el tiempo de cada muestra (aprox. 97-99 %); el coste de crear/configurar el objeto lector fue menor a 0,1 ms acumulado. En PhpSpreadsheet Xls, cada `load()` procesa de nuevo el OLE/Workbook; reutilizar el objeto Reader no evita esa carga. Mantener un `Spreadsheet` completo una sola vez y recorrerlo después no equivale al filtro por bloques y no se midió: el análisis aislado previo alcanzó cerca de 290 MiB aun filtrando bloques, por lo que cargar todo el libro de 55.765 filas podría exceder el límite de memoria disponible. No se forzó esa variante.

Esta medición no lee todo el libro y no incluye mapeo/validación, comparación con `loc_armas`, staging, escritura ni fingerprint; no valida rendimiento extremo a extremo ni permite extrapolar duración total. Los resultados apoyan que menos cargas reducen el coste de esta muestra, a cambio de mayor pico de memoria. No justifican todavía cambiar el tamaño predeterminado ni la estrategia productiva: se requiere benchmark completo aislado con controles de memoria antes de recomendarlo. La prueba no ejecutó Artisan, migraciones, colas, ni comandos de importación operativa.

#### Corrida completa y corte preventivo de memoria (23/10/2026)

Para completar el recorrido de datos y detener una corrida que superase 400 MiB de memoria PHP asignada:

```powershell
php tools\benchmarks\benchmark_armas_xls_reader.php --file='Gestion de informacion\ARMAS.xls' --expected-sha256=7BBB8FEFDA3FD0591D53F036099FA56E7CF98C22FEF07E10A673B97CA2A0B7F8 --rows=55765 --chunks=2500,5000 --max-peak-mb=400
```

`--rows=55765` cubre exactamente las filas de datos 2-55.766; el encabezado de la fila 1 no se cuenta. `listWorksheetInfo` determinó 55.766 filas totales. El hash y el tamaño se verificaron antes y después de la prueba y permanecieron iguales. La medición se ejecutó secuencialmente con PHP 8.4.0 y `memory_limit=512M`; no se conectó a MySQL y no utilizó el importador operativo.

| Bloque | Resultado | Bloques completos / cargas | Filas esperadas / leídas | Vacías / no vacías | Inicialización lector | Apertura/carga acumulada | Lectura celdas | Tiempo total / parcial | Pico PHP usado / asignado | Huella SHA-256 de celdas |
|---:|---|---:|---:|---:|---:|---:|---:|---:|---:|---|
| 2.500 | Completa | 23 | 55.765 / 55.765 | 0 / 55.765 | 0,000490 s | 736,965 s | 23,181 s | 764,462 s | 236,9 / 342 MiB | `a96e421f6ec82805517639f8d0852811b170953fc225217f92b03148de15d060` |
| 5.000 | Detenida por umbral (>400 MiB asignados) | 2 de hasta 12 | 55.765 / 10.000 | 0 / 10.000 | 0,000033 s | 48,427 s | 3,181 s | 52,078 s | 330,5 / 432 MiB | Parcial: `8b4bd93e6fdc80af245a6e0b0c173f64930edf4d44393475e30c1148d28d05b1` |

La pasada completa de 2.500 procesó todas las filas de datos, sin errores ni advertencias, y estableció una huella completa. La de 5.000 se detuvo tras el segundo bloque conforme al umbral, sin elevar `memory_limit` ni reintentar; sus 10.000 filas y huella parcial no se comparan como resultado completo con la huella de 2.500. Por este corte no se ejecutó la configuración opcional de 2.000.

La configuración de 2.500 es la única de esta comparación con resultado completo: tardó 764,462 s (12 min 44,462 s), con 23 cargas y un pico asignado de 342 MiB, 170 MiB por debajo del límite PHP de 512 MiB. La configuración de 5.000 cruzó el umbral preventivo al alcanzar 432 MiB asignados (80 MiB por debajo del límite PHP), aun cuando sólo había procesado 10.000 filas. No se puede cuantificar el ahorro total de pasar de 2.500 a 5.000, ni comparar huellas completas; el dato parcial sólo confirma que en esas primeras dos cargas 5.000 superó el umbral.

Recomendación: no cambiar el valor predeterminado productivo en esta tarea. La corrida aislada completa demuestra que 2.500 filas es viable para el lector solo, mientras que 5.000 no satisface el margen preventivo fijado. Sin embargo, la lectura aislada no incluye el consumo de mapeo, validación, inventario, staging o fingerprint; por ello no certifica una configuración de extremo a extremo. El archivo productivo, lotes, staging e inventario no fueron modificados. No se creó ni despachó ningún lote o job y no se ejecutó ningún worker. El chequeo de procesos encontró cero workers dedicados. La verificación final de las tablas operativas no pudo realizarse porque MySQL rechazó la conexión configurada en `127.0.0.1:3308`; no se intentó reparar ni cambiar esa conexión.

#### Prototipo aislado de reutilización de streams OLE (23/10/2026)

El prototipo `tools/benchmarks/prototype_armas_xls_ole_reuse.php` compara el lector estándar con una variante de diagnóstico: conserva en memoria los streams OLE ya extraídos y los proporciona a un objeto Reader nuevo para cada bloque. Así evita repetir la extracción OLE, pero sigue parseando el workbook BIFF en cada carga filtrada; no mantiene el libro completo ni usa base de datos. Es código de benchmark que sobrescribe un método protegido de PhpSpreadsheet y copia sus streams internos; no forma parte del importador productivo ni debe promoverse sin validar compatibilidad con cambios de versión.

Se probaron en serie dos pares en orden inverso, cada uno en proceso PHP separado, usando exactamente las filas 2-2001 (2.000 datos), bloque 500, límite de 512M y umbral preventivo 400 MiB. En ambas variantes se obtuvieron 2.000 filas no vacías, cero vacías y la misma huella `df95c1f6f02c7ba8d4545cb2907707402df5eb0b43abd811cb5cf6e6813e90db`; los SHA-256 del archivo antes y después coincidieron con el hash esperado.

| Par / orden | Modo | Bloques | Cargas OLE | Tiempo total | Carga de Reader | OLE extraído una vez | Pico PHP usado / asignado | Streams OLE retenidos |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| 1 (estándar → prototipo) | estándar | 4 | 4 | 81,435 s | 80,564 s | No separado | 164,2 / 270 MiB | 0 MiB |
| 1 | reuse_ole | 4 | 1 | 48,350 s | 47,473 s | 9,680 s | 167,2 / 170 MiB | 52,1 MiB |
| 2 (prototipo → estándar) | reuse_ole | 4 | 1 | 61,900 s | 60,949 s | 11,224 s | 167,2 / 170 MiB | 52,1 MiB |
| 2 | estándar | 4 | 4 | 85,997 s | 84,967 s | No separado | 164,2 / 270 MiB | 0 MiB |

El prototipo fue 33,085 s (40,6 %) más rápido en el primer par y 24,097 s (28,0 %) en el segundo. En esta muestra, el pico de memoria PHP usada aumentó 3 MiB, mientras que la memoria asignada máxima fue 100 MiB menor; además, el prototipo mantuvo 52,1 MiB de streams durante cada proceso. Los órdenes inversos reducen el sesgo de orden, aunque la caché de archivos del sistema operativo y la variación observada entre repeticiones siguen limitando la precisión. El resultado indica que extraer y retener los streams OLE puede evitar parte del coste de reapertura, pero todavía no mide una pasada completa ni prueba equivalencia con todo el libro.

Para repetir cada modo en PowerShell, en serie y con hash verificado por el script:

```powershell
$file = 'Gestion de informacion\ARMAS.xls'
php tools\benchmarks\prototype_armas_xls_ole_reuse.php --file="$file" --expected-sha256=7BBB8FEFDA3FD0591D53F036099FA56E7CF98C22FEF07E10A673B97CA2A0B7F8 --rows=2000 --chunk=500 --max-peak-mb=400 --mode=standard
php tools\benchmarks\prototype_armas_xls_ole_reuse.php --file="$file" --expected-sha256=7BBB8FEFDA3FD0591D53F036099FA56E7CF98C22FEF07E10A673B97CA2A0B7F8 --rows=2000 --chunk=500 --max-peak-mb=400 --mode=reuse_ole
```

La muestra respalda continuar el prototipo, no sustituir aún el lector de producción. El siguiente gate sería una prueba completa de lectura aislada con esta variante y el mismo límite/umbral; cualquier medición del flujo integral necesitaría después incorporar mapeo, validación y staging en una base desechable. No hubo cambios al importador productivo, configuración, base de datos, lotes, colas ni archivo XLS.
