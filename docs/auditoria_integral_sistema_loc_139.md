# Auditoría integral del sistema LOC 139

**Sistema Estatal para la Gestión y Control de Credenciales de Portación de Armas de Fuego del Personal Autorizado en la Licencia Oficial Colectiva No. 139**

| Dato | Valor |
|---|---|
| Fecha | 2026-11-12 |
| Repositorio | `sistema-de-credencializacion-loc/laravel-app`, rama `main`, 3 commits (último `0b4dca1 Commit 3`) |
| Estado del árbol | 54 archivos modificados o sin seguimiento, sin commit (cambios de las Fases 0–6 de esta sesión) |
| Modalidad | Solo lectura: sin cambios de código, sin migraciones, sin escrituras en la base de datos |
| Dictamen | **Requiere mejoras importantes** (sección 22) |

---

## 1. Resumen ejecutivo

El núcleo operativo **funciona y está razonablemente protegido**:
- Importación de los padrones LOC.
- Solicitudes con número de tarjeta.
- Prefiltro.
- Envío formal a impresión.
- Validación biométrica.
- Emisión y reimpresión registradas.
- Plantillas versionadas con aprobación.

Estos flujos usan transacciones con bloqueo, tienen pruebas de comportamiento (651 de 681 pasan) y, desde la Fase 0, todas las rutas con datos personales exigen sesión.

El sistema **no está listo para operación institucional estable**:
- **Integridad de la evidencia (H-01):** el candado de inmutabilidad biométrica se puede eludir. El expediente biométrico se comparte por CUIP entre solicitudes y el candado solo mira la credencial del elemento que se edita. En la base local ya hay dos elementos de solicitudes distintas que apuntan al mismo expediente.
- **Concurrencia (H-02, H-03):**
  - La indexación biométrica tiene el control de leases desactivado (`return; // BYPASS TEMPORAL DE LEASES`).
  - El folio de credencial no tiene secuencia: se calcula con `max(id)+1`, con condición de carrera, y no es consecutivo.
- **Protección de datos (H-04, H-05):** hay archivos Excel con padrones operativos y un script destructivo versionados en git.
- **Operación (H-06):**
  - Los roles de los que dependen credencialización e impresión no se pueden asignar sin editar la base de datos a mano.
  - No hay respaldos automatizados.
  - Cinco módulos visibles en el menú son solo interfaz: Consultas, Reportes, Entregas, Avisos y Administración de usuarios.

**Arquitectura recomendada:** conservar la actual (Laravel MVC con servicios). No hace falta una reconstrucción. Hace falta cerrar los huecos de integridad, sacar lógica de negocio de los controladores más grandes y completar o retirar los módulos que solo tienen interfaz.

## 2. Alcance y metodología

- **Etapa 0. Preparación:** verificación del repositorio, versiones, git y Docker, más el grafo de Graphify (`graphify-out/`, del 2026-11-10, anterior a los cambios de esta sesión, así que siempre se contrastó con el código).
- **Subagentes, en paralelo y con áreas separadas:**
  - `codebase-explorer`: arquitectura, módulos, base de datos, rendimiento y despliegue.
  - `code-reviewer`: seguridad, autorización y lógica de negocio.
  - `test-verifier`: inventario de pruebas, ejecución de la suite y causas raíz de los fallos.
- **Verificación directa del auditor:** revisé personalmente cada hallazgo Alto (evidencia citada) y recorrí las rutas con Playwright, solo con GET y sin sesión.
- **Fuentes adicionales:** la evidencia de Playwright con sesión reunida en esta misma jornada (Gestión, Credencialización, Impresión y Plantillas) y `docs/auditoria-perifericos.md`.
- **Nomenclatura de estados:** Confirmado (evidencia directa), Parcial (evidencia incompleta), Sospecha (requiere investigar) y No evaluado.

## 3. Restricciones respetadas
- No se modificó código ni configuración.
- No se ejecutaron migraciones ni seeders.
- No se escribió en la base de datos; solo se hicieron lecturas con `tinker`.
- No se leyó `.env` ni se abrieron los Excel con datos personales.
- Las pruebas se ejecutaron después de confirmar que usan SQLite `:memory:`.
- El único archivo creado es este informe.

## 4. Tecnologías y versiones verificadas

| Componente | Versión | Evidencia |
|---|---|---|
| PHP (CLI) | 8.4.0 | `php -v` |
| Laravel | 13.36.0 | `php artisan --version` |
| Base de datos | MariaDB 11.4 en Docker (solo `127.0.0.1:3308`, volumen con nombre, healthcheck) | `compose.yaml` |
| Excel | phpoffice/phpspreadsheet ^5.10 | `composer.json` |
| Frontend | Vite 7, Tailwind 3.4.17, Alpine 3.17, Node 22.19 | `package.json` |
| Pruebas | PHPUnit con SQLite `:memory:`; sin xdebug ni pcov (no se puede medir cobertura) | `phpunit.xml`, `php -m` |
| Producción prevista | Servidor Linux, worker de colas en systemd/supervisor | `deployment/` |

## 5. Arquitectura actual

- **Patrón:** Laravel MVC con capa de servicios en `app/Services`, que tiene 18 servicios: importadores LOC, prefiltro, secuencia, biometría y dispositivos. Usa Form Requests, Gates/Policy en `AppServiceProvider` y `SolicitudElementoPolicy`, y jobs en cola para las importaciones grandes.
- **Frontend:** Blade con componentes (`x-card`, `x-status-badge`, `x-button`), Alpine y Tailwind.
- **Lo que está bien:**
  - Los importadores LOC usan lectura por bloques, staging, jobs y bloqueos de idempotencia.
  - Los flujos críticos usan `DB::transaction` con `lockForUpdate`.
  - El disco biométrico es de solo lectura (`SafeReadOnlyLocalAdapter`).
  - La entrega de biométricos se audita.
- **Lo que está mal distribuido (Confirmado):**
  - **Lógica de negocio en controladores:**
    - `PrintQueueController` tiene 874 líneas: `validarBiometriaPorTarjeta` (~294), `previsualizacionElemento` (~240), `generarFolio` y la emisión.
    - `CredentialTemplateController` tiene 674 líneas.
  - **Duplicaciones:**
    - Generación de folio frente a `SolicitudTarjetaSequenceService` (`PrintQueueController.php:420-458`).
    - Resolución de la plantilla publicada (`PrintQueueController:512-519` y `CredentialTemplateController:303-309`).
    - URLs de biometría (`PrintQueueController:581-608` y `CredentialTemplateController:467-493`).
    - Reglas `enviada_impresion`/`listo_impresion` en dos controladores.
    - El patrón de carga repetido en los 4 `Loc*ImportController::upload`.
  - **Métodos y vistas demasiado grandes:**
    - `LocCredentialsXlsImporter::confirm` (~380 líneas), `SolicitudPrefiltroService::evaluate` (~367), `BiometricScannerService::scan` (~345), `LocWeaponsXlsImporter::commit` (~344) y `LocPersonalXlsImporter::stageWorkbook` (~326).
    - `resources/views/modules/impresion.blade.php`: 2172 líneas, unas 850 de JavaScript en línea.
  - **Estados como texto libre:** `estado_general`, `estado_tramite` y `resultado_prefiltro` ('Apto'/'Descartado') no tienen enum; los literales se reparten en más de 30 sitios. Solo `EstadoEmision` tiene enum.
- **Código muerto o ajeno (Confirmado):**
  - `PrintQueueDemoController.php` y `credential-demo-edit.blade.php` miden 0 bytes.
  - `welcome.blade.php` no se usa.
  - El modelo `SolicitudSecuencia` no se usa (se usa `DB::table`).
  - `loc-importador/` es un prototipo duplicado sin referencias.
  - `scratch/` contiene scripts de operación (ver H-05).
  - Hay 8 archivos .md de auditoría en `laravel-app/` y 29 en la carpeta superior.
  - `repomix-output.xml` (volcado del código) está versionado.
- **¿Es adecuada la arquitectura?** Sí, para el tamaño del sistema. No recomiendo capas nuevas. La mejora es extraer a servicios lo que ya está duplicado: folio, plantilla publicada, reglas de impresión y biometría.

## 6. Inventario de módulos

| Módulo | Rutas (`routes/web.php`) | Backend | Estado |
|---|---|---|---|
| Autenticación | `:19-27` | `AuthenticatedSessionController`, límite de 5 intentos por minuto por email+IP | Parcial: no hay recuperación de contraseña ni gestión de usuarios |
| Dashboard | `:29` | `DashboardController` (una sola consulta agregada) | Implementado |
| Gestión: personal LOC | `:34-37, 70-77` | `LocGestionController`, `LocPersonalXlsImporter`, `ProcessLocImportBatch` | Implementado |
| Gestión: armamento | `:38-47` | `LocWeaponsImportController`, importador y job | Implementado |
| Gestión: vigencias | `:48-57` | `LocCredentialsImportController`, `LocCredentialsWeaponsReconciler` | Implementado |
| Gestión: catálogo organizacional | `:58-69` | `LocCatalogoImportController`, versiones del catálogo | Implementado |
| Credencialización (solicitud, carga de Excel, prefiltro, envío) | `:80-95` | `SolicitudCredencialController`, `SolicitudElementosImporter`, `SolicitudPrefiltroService` | Implementado, con huecos (H-09, H-11) |
| Número de tarjeta | `store` | `SolicitudTarjetaSequenceService` (bloqueo, única, sin huecos) | Implementado y verificado |
| Folio de credencial | `validar-biometria` | `PrintQueueController::generarFolio` | Parcial: sin secuencia (H-03) |
| Impresión: cola y validación biométrica | `:97-120` | `PrintQueueController`, `BiometricResolverService` | Implementado |
| Impresión: emisión y reimpresión | `:117-120` | `registrarIntentoImpresion`, tabla `credencial_impresiones` | Implementado y verificado |
| Impresión: pestaña "Pendientes biométricos" | `:100` (`Route::view`) | Indicadores fijos en "—" | Solo interfaz |
| Plantillas (editor v1/v2, versiones, restaurar, aprobar) | `:101-135` | `CredentialTemplateController` | Implementado; publicada v6 |
| Biometría: indexación | Solo consola | `loc:biometricos:indexar`, `BiometricIndexingService` | Implementado con defectos (H-02, H-08) |
| Biometría: actualización y streaming | `:109-116` | `BiometricUpdateService`, `BiometricDeliveryService` | Implementado; candado eludible (H-01) |
| Dispositivos | `:150-180` | `DeviceController`, `DeviceDiagnostics` | Parcial: inventario real; el diagnóstico siempre devuelve "no disponible" y no hay integración con hardware |
| Captura en estación (cámara, firma, huellas) | Vista de impresión | Alpine: `getUserMedia`, `fetch` a SigWeb, carga de archivos | Parcial (ver `docs/auditoria-perifericos.md`) |
| Consultas y expedientes | `:144-147` | `Route::view` | Solo interfaz |
| Reportes | `:148` | `Route::view` | Solo interfaz |
| Entregas | `:137-142` | Closure con pestañas fijas | Solo interfaz |
| Avisos | `:189` | `Route::view` | Solo interfaz |
| Administración (usuarios, roles, catálogos, bitácora) | `:182-187` | Closure | Solo interfaz (H-06) |
| Perfil | `:190` | Muestra el usuario | Parcial |

**Flujo principal reconstruido (Confirmado):**
1. `store`: transacción y número de tarjeta; la solicitud queda en `recibida`.
2. `uploadElementos`: importación síncrona; los elementos quedan en `recibido`.
3. `ejecutarPrefiltro`: transacción con bloqueos y `upsert`; queda `resultado_prefiltro` y la solicitud pasa a `evaluada`.
4. `enviarAImpresion`: bloqueo; pasa a `enviada_impresion`.
5. `validarBiometriaPorTarjeta`: expediente, folio y credencial en `pendiente`; el elemento pasa a `listo_impresion`.
6. `registrarEmision`: la credencial pasa a `emitida`, con bitácora en `credencial_impresiones`.

## 7. Matriz de estado funcional

| # | Funcionalidad | Módulo | Estado | Evidencia | Problemas | Prioridad |
|---|---|---|---|---|---|---|
| 1 | Inicio de sesión con límite de intentos | Auth | Implementado y verificado | `AuthenticationTest` 7/7 | Sin recuperación de contraseña | P2 |
| 2 | Gestión de usuarios y roles | Auth/Admin | No implementado | Solo `loc:crear-superusuario`; `role` fuera de `$fillable` | H-06 | P1 |
| 3 | Importación de personal LOC | Gestión | Implementado y verificado | `LocPersonalImportTest` 35; recorrido real de 34 598 filas | — | — |
| 4 | Importación de armamento | Gestión | Implementado y verificado | `LocWeaponsImportTest` 54 | — | — |
| 5 | Importación de vigencias y conciliación | Gestión | Implementado y verificado | `LocCredentials*` | — | — |
| 6 | Catálogo organizacional versionado | Gestión | Implementado y verificado | `LocCatalogoImportTest` 17 | — | — |
| 7 | Alta de solicitud con número de tarjeta | Credencialización | Implementado y verificado | `SolicitudTarjetaSequenceTest`, bloqueo de fila | Concurrencia probada solo en secuencia | P3 |
| 8 | Carga de Excel de elementos | Credencialización | Implementado parcialmente | `SolicitudElementosImporter` | H-09 (sin control de responsable ni estado; carrera); síncrono | P1 |
| 9 | Prefiltro (personal, vigencias, armas, límites) | Credencialización | Implementado y verificado | `SolicitudPrefiltroEnginePhaseTwoThreeTest` y otros | H-07 (sin índices) | P3 |
| 10 | Envío formal a impresión | Credencialización | Implementado y verificado | `SolicitudEnvioImpresionTest` 10 | El envío solo queda en `Log::info` | P3 |
| 11 | Cola de impresión por tarjeta | Impresión | Implementado y verificado | `PrintQueueTest`, `CredencialEmisionTest` | — | — |
| 12 | Validación biométrica y asignación de folio | Impresión | Implementado parcialmente | `PrintQueueController:125-418` | H-03 (folio), H-12 (degrada emitidas) | P1 |
| 13 | Emisión y reimpresión con bitácora | Impresión | Implementado y verificado | `CredencialEmisionTest` 19 | Si una reimpresión falla tras registrarse, no hay forma de reportarlo | P3 |
| 14 | Inmutabilidad biométrica tras emitir | Impresión | Implementado parcialmente | `SolicitudElementoPolicy:24-33`, `BiometricUpdateService:53-56` | H-01 | **P0** |
| 15 | Código de barras real (Code 128) | Impresión | Implementado y verificado | `app/Support/Code128.php` y su prueba | El modal todavía muestra un dibujo de barras | P4 |
| 16 | Editor de plantillas, versiones y aprobación | Plantillas | Implementado y verificado | `CredentialTemplate*` 25; v6 publicada | H-14 (sin separación de funciones) | P3 |
| 17 | Paridad entre el modal y la impresión | Plantillas | Implementado y verificado | Comparación de 39 textos en Playwright | — | — |
| 18 | Impresión física en la Zebra | Impresión | Implementado sin validación suficiente | `window.print()` | Sin prueba física ni calibración de offsets | P2 |
| 19 | Indexación biométrica | Biometría | Implementado parcialmente | `BiometricIndexingService` | H-02, H-08 | **P0/P1** |
| 20 | Inventario de dispositivos | Dispositivos | Implementado y verificado | `DeviceManagementTest` 10 | — | — |
| 21 | Diagnóstico y comunicación con hardware | Dispositivos | Interfaz sin lógica completa | `DeviceDiagnostics.php:18-28` siempre "no disponible" | No hay SDK ni agente | P2 |
| 22 | Captura en estación (cámara, Topaz, Crossmatch) | Impresión | Implementado parcialmente | `impresion.blade.php` | Ver `docs/auditoria-perifericos.md` | P2 |
| 23 | Consultas y expedientes | Consultas | Interfaz sin lógica completa | `Route::view` | Ruta sin `auth` | P2 |
| 24 | Reportes | Reportes | Interfaz sin lógica completa | `Route::view` | — | P2 |
| 25 | Entregas y seguimiento | Entregas | Interfaz sin lógica completa | Closure con pestañas fijas | `estado_entrega` existe pero nada lo escribe | P2 |
| 26 | Avisos | Avisos | Interfaz sin lógica completa | `Route::view` | — | P4 |
| 27 | Bitácora transversal | Admin | No implementado | — | H-16 | P2 |
| 28 | Respaldos y recuperación | Operación | No implementado | Sin scripts ni tareas programadas | H-10 | P1 |

## 8. Base de datos

- **Estructura:** 39 migraciones. Hay FK con `restrictOnDelete` donde importa (`solicitud_id`) y únicos en número de tarjeta, consecutivo y folio, así como en `loc_personas.cuip` y `loc_armas.matricula`.
- **Hallazgos (Confirmado):**
  - **Valores por defecto:** las migraciones ponen `'pendiente'` en `estado_general` y `estado_tramite`, pero el código escribe `'recibida'`/`'recibido'`. Las columnas son texto sin validación ni enum (Medio).
  - **Restricciones faltantes:**
    - `credenciales_emitidas.solicitud_elemento_id` no es único: un elemento podría tener varias credenciales y solo lo evita el código (Medio).
    - `solicitud_elementos` no tiene único `(solicitud_id, cuip)`; los duplicados dentro de una solicitud solo los detecta el prefiltro (Bajo).
    - `credencial_impresiones.estacion_id` y `biometrico_index_runs.ultimo_checkpoint_id` no tienen FK (Bajo; la primera es intencional mientras no exista la tabla de estaciones).
  - **Desnormalización:** `solicitudes.total_*` se copian al evaluar y pueden diferir de los conteos reales. `credenciales_emitidas.numero_tarjeta` duplica el de la solicitud (Bajo).
  - **Tipos de enum mezclados:** `ambito_biometrico` es un enum nativo de MariaDB y el resto son `string` (Bajo).
  - **Índices redundantes:** `solicitudes_tarjeta_estado_index` y `(solicitud_id, folio)` (Bajo).
  - **Datos sembrados en una migración:** la secuencia de tarjetas (aceptable). No existe la secuencia de folio (ver H-03).
- **Concurrencia:** hay `lockForUpdate` en tarjeta, prefiltro, envío, validación biométrica, emisión y actualización biométrica, con orden de bloqueo de armas determinista. Las excepciones son el folio (H-03) y la carga de Excel (H-09).

## 9. Seguridad

**Fortalezas verificadas:**
- Desde la Fase 0, todas las rutas con datos personales exigen `auth` y permiso (`can:` o verificación en el controlador). Lo confirmé con Playwright: sin sesión, todas las rutas protegidas redirigen a `/login`.
- No hay CSRF exceptuado, SQL con texto concatenado, `$request->all()` ni `$guarded=[]`.
- Los únicos `{!! !!}` son seguros (el JSON del editor con `JSON_HEX_*` y el SVG de Code128 escapado).
- El login tiene límite de intentos y regenera la sesión.
- `APP_DEBUG` vale `false` por defecto.
- El disco biométrico es de solo lectura y tiene protección contra recorrido de rutas.

**Matriz de autorización (resumen):**

| Grupo de rutas | Middleware | Verificación adicional |
|---|---|---|
| `/gestion/*` | `auth`, `can:access-administration` | Superadmin (el modo demo puede hacer GET; H-13) |
| `/credencializacion/*` | `auth`, `can:access-credencializacion` | Prefiltro y envío: superadmin o responsable; **carga de Excel: ninguna (H-09)** |
| Cola y validación biométrica | `auth` | `access-print-queue` y estado `enviada_impresion` |
| Previsualización y streaming biométrico | `auth` | Solo `view-biometrics`; **no revisa el estado (H-11)** |
| Emisión y reimpresión | `auth` | `print-credentials` y estado |
| Plantillas (guardar, aprobar) | `auth`, `can:manage/approve-…` | Sin separación de funciones (H-14) |
| `/impresion/plantilla/recursos/{file}` | **ninguno** | Regex de nombre; caché `public` (H-15) |
| `/entregas`, `/consultas*`, `/reportes`, `/avisos`, `/perfil` | **ninguno** | Vistas estáticas, sin datos hoy (H-15) |

## 10. Calidad y mantenibilidad

- **Problemas reales:**
  - Lógica de negocio en controladores grandes.
  - Duplicaciones (sección 5).
  - Estados como texto en más de 30 sitios.
  - Mensajes de error que exponen `$e->getMessage()` al usuario (`PrintQueueController:432-436`, `SolicitudCredencialController:302-304, 338-341`, `SolicitudElementosImporter:45`).
  - Comando de indexación con doble codificación UTF-8 (H-08).
  - README todavía es el de Laravel por defecto.
- **Lo que no es problema:** la convención de nombres en español en el dominio y en inglés en la infraestructura es consistente. No recomiendo cambios estéticos.

## 11. Interfaz y experiencia de usuario

- **Comprobado con Playwright en esta jornada:**
  - Homologación visual de Gestión, Credencialización y Dashboard.
  - Paridad entre el modal y la impresión en 39 de 39 textos.
  - Sin desborde horizontal a 1366 y 1920 px.
  - Botones de acción encima de la tabla.
  - Folio y CUIP en una sola línea con la v6.
- **Pendientes observados:**
  - Mensajes flash duplicados en el detalle de solicitud (Bajo).
  - El modal todavía muestra un código de barras dibujado, no el real (Bajo).
  - Cinco módulos del menú son pantallas estáticas que dicen "No hay información" o "demostración". El operador no puede distinguir qué módulo funciona (Medio, P2).
  - No evaluado: vista móvil por debajo de 768 px; accesibilidad con lector de pantalla.

## 12. Pruebas y cobertura

- **Inventario:** 47 archivos (42 Feature y 5 Unit), 681 pruebas, sin pruebas de navegador.
- **Ejecución completa** (SQLite `:memory:`): **651 pasan y 30 fallan** (4014 aserciones, 162 s). Los 30 fallos son los mismos que antes de esta jornada.

| Grupo de fallos | Cantidad | Causa raíz |
|---|---|---|
| `BiometricIndexCommandTest` | 16 | **Defecto real:** el comando tiene 107 líneas con doble codificación (`IndexBiometricosCommand.php:246`) y los mensajes salen corruptos (H-08) |
| `BiometricIndexCommandTest` 16 y `BiometricConcurrencyAndLeaseTest` | 2 | **Defecto real:** bypass de leases (H-02) |
| `BiometricIndexCommandTest` 15 | 1 | Prueba desactualizada (`--force` desaloja el lease a propósito) |
| `SafeReadOnlyLocalAdapterTest` | 2 | Una por entorno (Flysystem bloquea antes, con otra excepción). La otra es **una decisión pendiente**: `operador` en `view-biometrics` |
| `SharedVisualComponentsTest`, `SolicitudDetalleElementosViewTest`, `PhaseTwoTwo` 13 | 9 | Pruebas desactualizadas tras el rediseño de vistas |

- **Calidad de las pruebas:**
  - Los flujos de negocio se prueban por comportamiento.
  - Hay pruebas frágiles que comprueban clases CSS o anchos en px (`SharedVisualComponentsTest:22-36`, `SolicitudElementosOptimizacionTablaTest:128-134`).
- **Huecos (Alto):**
  - No hay matriz rol × ruta (75 rutas; solo 13 archivos comprueban 403).
  - El 403 del streaming biométrico no se prueba.
  - La concurrencia de folio y tarjeta solo se prueba en secuencia.
  - El bypass de leases queda oculto entre los fallos que se tratan como "conocidos".

## 13. Rendimiento

- **H-07 (Confirmado en código, impacto no medido):** el prefiltro filtra con `UPPER(TRIM(cuip))` y `UPPER(TRIM(matricula))` (`SolicitudPrefiltroService:284-285, 481, 492, 507`). Eso anula los índices únicos y provoca recorridos completos sobre unas 34 600 personas y 56 000 armas, y en `:494` lo hace bajo `lockForUpdate`.
- **Carga de Excel de solicitud síncrona:** lee el libro completo en memoria y hace un `create` por fila, con límite de 50 MB. Las importaciones LOC sí usan bloques y cola.
- **Validación biométrica:** guarda elemento por elemento dentro de una transacción con bloqueos (Sospecha: crecerá con el tamaño de las tarjetas).
- **Paginación:** listas de 15 y detalle de 50 (correcto). El dashboard hace una sola consulta agregada.

## 14. Configuración y despliegue

- **Docker:** `compose.yaml` solo levanta MariaDB, ligada a 127.0.0.1 y con healthcheck; no hay contenedor de aplicación. El despliegue previsto es un servidor Linux con systemd o supervisor.
- **Worker de colas (Sospecha, Medio):** `queue:work` corre sin `--connection`, con 2 procesos y una lista de colas que incluye nombres que no existen.
  - Usa la conexión `database`, cuyo `retry_after` sale de `DB_QUEUE_RETRY_AFTER`. `.env.example` lo fija en 3900; si el `.env` real no lo define, vale 90 y un job largo podría ejecutarse dos veces.
  - Las conexiones `loc_*` declaradas en `config/queue.php` no se usan.
- **Sin respaldos (H-10):** no hay script de respaldo, `mysqldump` ni tareas programadas (`routes/console.php` solo tiene `inspire`). La documentación pide respaldos manuales.
- **Entornos:** existen `.env.docker` y `.env.sqlite.backup` (no se leyeron). El modo demo exige `APP_ENV=local`.
- **Logs:** diarios, 14 días de retención.
- **No hay HTTPS** previsto en la configuración. Es necesario para la cámara por navegador (ver la auditoría de periféricos).

## 15. Hallazgos clasificados

No se identificaron hallazgos **Críticos** confirmados. H-04 pasa a Crítico si el repositorio se comparte fuera de la máquina de desarrollo.

| ID | Prioridad | Área | Descripción | Evidencia | Impacto | Recomendación |
|---|---|---|---|---|---|---|
| H-01 | **Alto** | Biometría e integridad | El candado de inmutabilidad solo revisa la credencial del elemento editado; el expediente se comparte por CUIP y ámbito | `SolicitudElementoPolicy.php:24-33`, `BiometricUpdateService.php:53-56, 78-91`; en la base local los elementos 42 (sol. 6) y 169 (sol. 9) comparten el expediente 116 | Se puede cambiar la foto o huella de una credencial ya emitida desde otra solicitud; la reimpresión con el folio original muestra la biometría nueva | Bloquear si cualquier elemento del expediente tiene credencial emitida y guardar en la credencial los IDs de archivo al emitir |
| H-02 | **Alto** | Indexación biométrica | Control de lease desactivado | `BiometricIndexingService.php:1073-1076` (`return; // BYPASS TEMPORAL DE LEASES`) | Dos workers pueden indexar a la vez, sin fencing; riesgo de expedientes inconsistentes | Restaurar la verificación y la renovación; las pruebas que hoy fallan ya lo cubren |
| H-03 | **Alto** | Folios | La secuencia `folio_credencial` no existe; el respaldo `max(id)+1` y un bucle `exists()` tienen carrera, y el folio no es consecutivo | `PrintQueueController.php:420-458`; `solicitud_secuencias` solo tiene `tarjeta_credencial` | Dos validaciones simultáneas pueden chocar, deshacer una tarjeta completa o, al agotar 50 intentos, devolver un folio duplicado | Migración que siembre `folio_credencial` y reutilizar `SolicitudTarjetaSequenceService` |
| H-04 | **Alto** | Protección de datos | Excel con padrones operativos versionados en git | `git ls-files "Gestion de informacion"`: ARMAS.xls, Elementos.xls, Vigencia.xls… | Datos personales (CUIP, nombres, armas) en el historial de git | Sacarlos del repositorio, agregarlos a `.gitignore` y evaluar limpiar el historial |
| H-05 | **Alto** | Operación | Script destructivo versionado | `scratch/ejecutar_masivo.php:16-19` (FK desactivadas, `truncate` de leases, `update` masivo) | Ejecutarlo por error interrumpe corridas y borra el control de concurrencia | Retirar `scratch/` del repositorio o moverlo a un comando con confirmación |
| H-06 | **Alto** | Roles y operación | Solo `superadmin` se puede asignar (por consola); `credencializador`, `impresion` y `operador` no tienen forma de asignación; plantillas y dispositivos usan IDs en `.env` | `LocSuperuserProvisioner.php:57,72`; `User.php:13` | Sin editar la base de datos a mano, solo un superadmin puede operar credencialización e impresión; los permisos no se pueden auditar | Matriz única de roles y comando o pantalla de asignación con bitácora |
| H-07 | Medio | Rendimiento | Búsquedas del prefiltro sin índices y con bloqueo | `SolicitudPrefiltroService:481-507` | Prefiltros lentos y bloqueos largos con padrones grandes | Normalizar CUIP y matrícula al importar y comparar en igualdad directa |
| H-08 | Medio | Calidad | Comando de indexación con doble codificación UTF-8 | `IndexBiometricosCommand.php` (107 líneas) | Mensajes corruptos; 16 pruebas en rojo | Volver a codificar el archivo |
| H-09 | Medio | Credencialización | La carga de Excel no revisa responsable ni estado y su verificación `exists()` queda fuera de la transacción | `SolicitudCredencialController:286-309`, `SolicitudElementosImporter:25-30` | Un operador puede cargar en un expediente ajeno; dos cargas simultáneas duplican elementos | Usar `autorizarDictamen`, exigir `recibida` y bloquear la solicitud |
| H-10 | Medio | Operación | Sin respaldos ni recuperación | `compose.yaml`, `routes/console.php` | Pérdida de datos ante una falla | Respaldo programado y prueba de restauración |
| H-11 | Medio | Autorización | Previsualización y streaming biométrico sin regla de estado; `view-biometrics` incluye 6 roles, entre ellos `operador` | `PrintQueueController.php:492, 734`; `AppServiceProvider.php:45` | Consulta de datos y biometría de elementos descartados recorriendo IDs (auditada) | Aplicar la regla de la cola y decidir si `operador` debe ver biometría |
| H-12 | Medio | Impresión | Volver a validar puede regresar a `pendiente_biometrico` un elemento con credencial emitida | `PrintQueueController.php:242-244, 284-287, 332-335` | Bloquea la reimpresión y distorsiona las métricas | Excluir de la validación los elementos emitidos |
| H-13 | Medio (Sospecha) | Configuración | El modo demo abre la lectura de Gestión si se configura mal | `LocalDemoAccess.php:15-29`, `AppServiceProvider.php:38` | Exposición de padrones en una copia de base con otro nombre | Quitar el modo demo de `access-administration` |
| H-14 | Bajo | Plantillas | Quien guarda una versión puede aprobarla; se pueden aprobar versiones antiguas | `CredentialTemplateController.php:260-293` | Sin control de doble firma | Exigir un aprobador distinto del autor |
| H-15 | Bajo | Rutas | Rutas sin `auth`: recursos de plantilla (con caché pública) y cinco módulos estáticos | `routes/web.php:130-132, 137-194` | Hoy sin datos; riesgo cuando se conecten | Agruparlas bajo `auth` |
| H-16 | Bajo | Bitácora | No se registran la creación de solicitudes, la carga de elementos, la asignación de folio, los inicios de sesión ni los cambios de rol; el envío solo queda en `Log::info` | — | Trazabilidad incompleta para auditorías | Tabla de bitácora de acciones |
| H-17 | Bajo | Seguridad | Errores internos expuestos al usuario | Sección 10 | Filtra SQL y rutas | Mensaje genérico y detalle en el log |
| H-18 | Bajo | Biometría | La extensión del archivo subido viene del cliente | `BiometricUpdateService.php:123,127` | Bajo: el archivo queda fuera de `public` | Derivar la extensión del tipo real |
| H-19 | Bajo | Base de datos | Valores por defecto incoherentes, falta un único en `credenciales_emitidas.solicitud_elemento_id`, índices redundantes | Sección 8 | Inconsistencias silenciosas | Migración de alineación |
| H-20 | Bajo | Mantenibilidad | Código muerto, prototipos y documentos sueltos | Sección 5 | Confusión y ruido | Limpieza del repositorio |

## 16. Riesgos técnicos

1. **Integridad legal de la credencial** (H-01, H-12): la credencial emitida no "congela" su evidencia biométrica.
2. **Concurrencia en procesos con varios operadores** (H-02, H-03, H-09): los problemas aparecen recién con uso simultáneo.
3. **Datos personales fuera de control** (H-04).
4. **Dependencia de un solo superadmin** (H-06).
5. **Continuidad operativa** (H-10, y H-13 por configuración).
6. **Pruebas en rojo normalizadas:** 30 fallos "conocidos" ocultan dos defectos reales.

## 17. Funcionalidades incompletas

- **Solo interfaz:** Consultas, Reportes, Entregas (aunque `estado_entrega` existe), Avisos, Administración de usuarios, roles y bitácora, y la pestaña "Pendientes biométricos".
- **Parciales:**
  - Folio.
  - Diagnóstico de dispositivos.
  - Captura en estación: falta HTTPS, la selección de cámara y SigWeb con su biblioteca oficial.
  - Impresión física (sin calibración de offsets en el hardware).
  - Perfil.
  - Autenticación (sin recuperación de contraseña).

## 18. Deuda técnica

- Lógica de negocio en `PrintQueueController` y `CredentialTemplateController`.
- `impresion.blade.php` con unas 850 líneas de JavaScript en línea.
- Estados como texto sin enum.
- Duplicación en los 4 controladores de carga LOC.
- 30 pruebas en rojo, de las cuales 24 son desactualizadas o de codificación.
- Pruebas que dependen de clases CSS o anchos en px.
- Prototipos y documentos sueltos.

## 19. Recomendaciones (principios)

- No reconstruir: el núcleo es sólido.
- Atacar primero lo que compromete la integridad legal y la concurrencia.
- Extraer a servicios solo lo que ya está duplicado (folio, plantilla publicada, reglas de impresión), sin crear capas nuevas.
- Decidir con el área usuaria si los módulos que solo tienen interfaz se completan o se ocultan del menú.
- Dejar la suite en verde: corregir los dos defectos reales y actualizar las 24 pruebas desactualizadas.

## 20. Plan de mejora por etapas (no implementado)

| Prioridad | Intervención | Problema | Archivos o módulos | Dependencias y riesgos | Pruebas necesarias | Orden |
|---|---|---|---|---|---|---|
| **P0** | Congelar la biometría al emitir | H-01 | `BiometricUpdateService`, `SolicitudElementoPolicy`, migración de IDs de archivo en `credenciales_emitidas` | Migración aditiva; definir la regla con el área jurídica | Actualizar desde otra solicitud con el mismo CUIP devuelve 403; la reimpresión usa los archivos congelados | 1 |
| **P0** | Restaurar el control de leases | H-02 | `BiometricIndexingService:1073` | Revisar por qué se desactivó (probablemente un problema de rendimiento o de las pruebas) | Las 2 pruebas en rojo pasan | 2 |
| **P0** | Sacar los datos y el script del repositorio | H-04, H-05 | `Gestion de informacion/`, `scratch/`, `.gitignore` | Limpiar el historial exige coordinarse con quien tenga copias | — | 3 |
| **P1** | Secuencia de folio | H-03 | Migración, `PrintQueueController::generarFolio`, `SolicitudTarjetaSequenceService` | Definir el formato del folio y el reinicio anual | Unicidad, consecutivo y prueba con dos transacciones | 4 |
| **P1** | Roles administrables | H-06 | Comando o pantalla de usuarios, matriz de roles en un solo lugar, bitácora | Acordar la matriz con la operación | Matriz rol × ruta (403/200) | 5 |
| **P1** | Carga de Excel segura | H-09 | `SolicitudCredencialController::uploadElementos`, `SolicitudElementosImporter` | — | Responsable, estado y doble carga | 6 |
| **P1** | Respaldos | H-10 | Script con `mysqldump`, programación, procedimiento de restauración | Infraestructura | Restauración de prueba | 7 |
| **P1** | Reglas de estado en la vista biométrica y en la validación | H-11, H-12 | `PrintQueueController` | Decisión sobre `operador` | 403 por estado; los elementos emitidos no se degradan | 8 |
| **P2** | Completar u ocultar módulos de solo interfaz; HTTPS y periféricos | §17 | Consultas, Reportes, Entregas, Administración; `docs/auditoria-perifericos.md` | Decisión funcional | Por módulo | 9 |
| **P2** | Bitácora de acciones e inicio de sesión | H-16 | Tabla y listeners | — | Registro por acción | 10 |
| **P3** | Índices del prefiltro, Excel en cola, extraer servicios de impresión y plantilla, enums de estado | H-07, §5 | `SolicitudPrefiltroService`, `PrintQueueController`, `CredentialTemplateController` | Cambio de comportamiento a revisar | Suite completa en verde | 11 |
| **P3** | Suite en verde | §12 | 24 pruebas desactualizadas y el archivo con doble codificación | — | `php artisan test` sin fallos | 12 |
| **P4** | Ajustes visuales | §11 | Código de barras real en el modal, mensajes flash duplicados | — | Playwright | 13 |

## 21. Limitaciones de la auditoría

- No se leyeron `.env`, `.env.docker` ni `.env.sqlite.backup`. La conexión de cola y la seguridad de la sesión en el despliegue real dependen de esos archivos.
- La sesión de Playwright caducó durante la auditoría. Las pantallas autenticadas se evaluaron con la evidencia reunida antes en la misma jornada, no en este recorrido.
- No se midió cobertura (no hay xdebug ni pcov) ni rendimiento con carga real.
- No se probó hardware: Zebra, cámara, Topaz y Crossmatch.
- No se revisó a fondo la corrección interna de los importadores LOC ni de `BiometricIndexingService`, más allá de los hallazgos citados.
- No se abrieron los Excel versionados. Que contengan datos reales se infiere de su nombre y de su uso en importaciones de 34 000 filas.

## 22. Dictamen técnico final

**Requiere mejoras importantes.**

- **Fundamento:**
  - El núcleo de negocio (importación, solicitud, prefiltro, envío, cola, emisión y plantillas) está implementado, protegido por autenticación y permisos, usa transacciones con bloqueo y tiene pruebas de comportamiento: 651 de 681 pasan y las 30 que fallan se conocen y tienen causa identificada.
  - Por eso no corresponde "requiere intervención estructural".
- **Por qué no es "Sólido con observaciones"**, todos confirmados:
  1. La inmutabilidad de la evidencia biométrica de una credencial emitida se puede eludir (H-01).
  2. Dos mecanismos de concurrencia en procesos críticos están desactivados o ausentes (H-02, H-03).
  3. Hay datos operativos versionados en git (H-04).
  4. La operación depende de editar la base de datos para asignar roles (H-06).
  5. No hay respaldos (H-10).
  6. Casi un tercio de los módulos del menú son solo interfaz.

**Primera etapa recomendada:** P0, es decir H-01, H-02 y H-04/H-05, en ese orden. Son cambios acotados, de alto impacto en integridad legal y protección de datos, y no dependen de decisiones funcionales pendientes.
