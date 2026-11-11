# Graph Report - laravel-app  (2026-11-10)

## Corpus Check
- 301 files · ~227,177 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 14 file(s) not represented in the graph (top: (none) 6, .xml 2, .conf 1)

## Summary
- 2546 nodes · 5627 edges · 198 communities (64 shown, 134 thin omitted)
- Extraction: 94% EXTRACTED · 6% INFERRED · 0% AMBIGUOUS · INFERRED: 353 edges (avg confidence: 0.85)
- Token cost: 226,309 input · 0 output

## Community Hubs (Navigation)
- Modelos Eloquent y relaciones
- Entrega segura de biométricos
- Pruebas importación armamento
- Base de pruebas y modelos LOC
- Pruebas elementos de solicitud
- Conciliación credenciales-armas
- Parser de nombres biométricos
- Configuración Composer
- Cola de impresión y resolver
- Importador de credenciales
- Importador de personal LOC
- Usuarios y autorización
- Incidencias biométricas
- Mapeo de filas de personal
- Ejecuciones de indexación
- Staging de importaciones
- Controladores de importación
- Pruebas importación personal
- Migraciones de esquema LOC
- Prefiltro de solicitudes
- Escáner biométrico
- Frontend npm y assets
- Relaciones persona-arma
- Regresión credenciales
- Componentes visuales compartidos
- Clasificación de motivos
- Jobs de importación en cola
- Política de credenciales
- Servicio de indexación biométrica
- Rutas web y controladores
- Importador de catálogo
- Importador de armamento
- Comando indexar biométricos (tests)
- Provisión de superusuario
- Ámbito y expedientes biométricos
- Importación de elementos
- Estados de archivo biométrico
- Respuestas HTTP y vistas
- Gestión de dispositivos
- Solicitudes y Form Requests
- Normalización de credenciales XLS
- Hash streaming
- Integración indexación biométrica
- Conciliación de identidad
- Resolver de ámbito biométrico
- Lectura XLS armamento por bloques
- Catálogo área solicitante
- Completitud de expedientes
- Docker Compose MariaDB
- Migraciones biométricas
- Motor de prefiltro (tests)
- Plantillas de credencial
- Migraciones base
- Regresión personal
- Docs almacenamiento solo lectura
- Remediación de prefiltro
- Acceso demo local
- Editor de plantillas v2
- Atributos de persona
- Prototipo OLE armas
- Comando IndexBiometricos
- Secuencia de tarjetas
- Migraciones prefiltro/ámbito
- Docs importación de personal
- Read filters XLS
- Pruebas editor de plantillas
- Pruebas de dispositivos
- Diagnóstico de errores de importación
- Controlador LocGestion
- Lector OLE de credenciales
- Pruebas demo local
- Vista previa y actualización biométrica
- Pruebas secuencia de tarjetas
- Auditorías Etapa 2.2/2.3
- Docs importación armamento
- Infraestructura control biométrico
- Reglas del proyecto (CLAUDE.md)
- Estado de revisión biométrica
- Validación actualización biométrica
- Vistas credencialización
- Estrategia de blindaje
- Auditoría pericial con snapshots
- Cierre Etapa 2.2
- Pruebas de autenticación
- Pruebas de dashboard
- Previsualización plantilla v2
- Hallazgos SafeReadOnlyAdapter
- Migración create users table
- Migración create jobs table
- Migración add datos loc to loc personas 
- Migración add datos loc to loc personas 
- Vista de gestión
- Integridad de solicitudes
- Migración create cache table
- Migración create devices and device even
- Migración add role to users table
- Migración expand cuip columns to 100 cha
- Migración add dual weapons support to lo
- Migración add consecutivo tarjeta to sol
- Migración add catalogo organizacional to
- Migración add codigos descarte to solici
- Seeder de base de datos
- Docs workers de producción
- Migración expand cuip columns to 100 cha
- Vista gestion-armas-import-preview
- Vista gestion-import-preview
- Vista gestion-credenciales-import-preview
- Persistencia de negocio
- Esquema de prefiltro
- Asociación solicitud-expediente
- Migración create loc import batches tabl
- Migración create loc personas table
- Migración create loc armas table
- Migración create loc persona armas table
- Migración create loc catalogo organizaci
- Migración create solicitudes table
- Migración create credenciales emitidas t
- Migración create biometrico archivos tab
- Migración create biometrico auditorias t
- Migración create biometrico index runs t
- Migración create biometrico incidencias 
- Migración create biometrico index leases
- Migración create biometrico archivo vers
- Expediente técnicamente completo
- Vista detalle-elementos
- Vista administracion
- Vista credential-template-editor
- Vista form
- Vista index
- Vista show
- Vista gestion-import-preview
- Vista dashboard
- Vista avisos
- Vista consultas
- Vista credential-template-print
- Vista entrega
- Vista gestion-catalogo-import-preview
- Vista gestion-catalogo-version
- Vista gestion-catalogo-vigente
- Vista impresion
- Vista perfil
- Vista reportes
- robots.txt (allow all)
- Inter font - SIL Open Font License 

## God Nodes (most connected - your core abstractions)
1. `LocImportBatch` - 121 edges
2. `User` - 108 edges
3. `TestCase` - 82 edges
4. `SolicitudElemento` - 76 edges
5. `LocWeaponsImportTest` - 60 edges
6. `SafeReadOnlyLocalAdapterTest` - 59 edges
7. `LocCredentialsXlsImporter` - 58 edges
8. `LocPersonalXlsImporter` - 58 edges
9. `BiometricoExpediente` - 52 edges
10. `BiometricIndexingService` - 52 edges

## Surprising Connections (you probably didn't know these)
- `Reglas de base de datos (no migraciones destructivas, respetar FKs)` --semantically_similar_to--> `Seguridad e integridad (no secretos .env, no datos reales, no operaciones destructivas)`  [INFERRED] [semantically similar]
  .claude/rules/database-testing.md → CLAUDE.md
- `Reglas backend Laravel (Form Requests, policies, transacciones, evitar N+1)` --semantically_similar_to--> `Principios de desarrollo (simple, reutilizar, cambios pequenos)`  [INFERRED] [semantically similar]
  .claude/rules/laravel-backend.md → CLAUDE.md
- `Ciclo de vida asincrono de importacion (pendiente -> procesando -> en_revision)` --semantically_similar_to--> `Flujo staging -> revision -> confirmacion con huella SHA-256`  [INFERRED] [semantically similar]
  docs/guia-automatizacion-workers-produccion.md → README.md
- `{closure#1}()` --references--> `ProcessLocWeaponsImportBatch`  [EXTRACTED]
  tests/Feature/LocWeaponsImportTest.php → app/Jobs/ProcessLocWeaponsImportBatch.php
- `{closure#7}()` --references--> `LocArma`  [EXTRACTED]
  tests/Feature/LocWeaponsImportTest.php → app/Models/LocArma.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Correcciones A-D de la Etapa 2.2** — implementacion_correctiva_etapa_2_2_correccion_a, implementacion_correctiva_etapa_2_2_correccion_b, implementacion_correctiva_etapa_2_2_correccion_c, implementacion_correctiva_etapa_2_2_correccion_d [EXTRACTED 1.00]
- **Flujo de entrega biometrica de solo lectura** — auditoria_independiente_etapa_2_3_streamarchivo, auditoria_independiente_etapa_2_3_gate_view_biometrics, auditoria_independiente_etapa_2_3_assertsafepath, auditoria_independiente_etapa_2_3_safereadonlylocaladapter, implementacion_correctiva_etapa_2_2_registraracceso, cierre_etapa_2_2_preparacion_etapa_2_3_disco_biometricos [INFERRED 0.85]
- **Reglas de trabajo del agente en el proyecto** — claude_principios_desarrollo, claude_seguridad_integridad, _claude_rules_database_testing_database_rules, _claude_rules_database_testing_testing_rules, _claude_rules_laravel_backend_backend_rules [INFERRED 0.85]
- **Flujo de entrega biometrica segura de solo lectura** — implementacion_etapa_2_3_biometricdeliveryservice, implementacion_etapa_2_3_view_biometrics_gate, implementacion_etapa_2_3_biometricoauditoria_registraracceso, implementacion_etapa_2_3_disco_biometricos, implementacion_etapa_2_3_safereadonlylocaladapter, implementacion_etapa_2_3_assertsafepath [EXTRACTED 1.00]
- **Pipeline de importaciones LOC (lote, staging, revision, confirmacion)** — readme_importacion_padron_personal, readme_importacion_armamento, docs_guia_automatizacion_workers_produccion_importacion_credenciales, readme_loc_import_batches, docs_guia_automatizacion_workers_produccion_ciclo_asincrono, docs_guia_automatizacion_workers_produccion_colas_dedicadas [INFERRED 0.85]

## Communities (198 total, 134 thin omitted)

### Community 0 - "Modelos Eloquent y relaciones"
Cohesion: 0.04
Nodes (13): {closure#2}(), BiometricoArchivoVersion, BiometricoAuditoria, BiometricoIndexLease, BiometricoSlotCandidato, CredencialEmitida, CredentialTemplate, CredentialTemplateVersion (+5 more)

### Community 1 - "Entrega segura de biométricos"
Cohesion: 0.06
Nodes (3): BiometricoReadOnlyException, BiometricDeliveryService, SafeReadOnlyLocalAdapterTest

### Community 3 - "Base de pruebas y modelos LOC"
Cohesion: 0.12
Nodes (8): LocArma, LocCatalogoOrganizacional, LocCatalogoVersion, LocPersona, LocVigenciaCredencial, SolicitudCredencial, {closure#1}(), TestCase

### Community 4 - "Pruebas elementos de solicitud"
Cohesion: 0.07
Nodes (3): SolicitudDetalleElementosViewTest, SolicitudElementosFiltrosTest, SolicitudElementosOptimizacionTablaTest

### Community 5 - "Conciliación credenciales-armas"
Cohesion: 0.07
Nodes (11): ClasificarMotivosHistoricosCommand, CreateLocSuperuser, ReconcileLocCredentialsWeaponsCommand, {closure#1}(), {closure#1}(), {closure#1}(), {closure#2}(), {closure#3}() (+3 more)

### Community 6 - "Parser de nombres biométricos"
Cohesion: 0.06
Nodes (16): BiometricoCategoria, FIRMA, FOTO, PULGAR_DERECHO, PULGAR_IZQUIERDO, BiometricoTipoIdentificacion, CORTO_RFC, ESTANDAR (+8 more)

### Community 7 - "Configuración Composer"
Cohesion: 0.04
Nodes (47): pestphp/pest-plugin, php-http/discovery, autoload, autoload-dev, psr-4, psr-4, config, allow-plugins (+39 more)

### Community 8 - "Cola de impresión y resolver"
Cohesion: 0.07
Nodes (13): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#13}(), SolicitudElemento, SolicitudElementoPolicy (+5 more)

### Community 9 - "Importador de credenciales"
Cohesion: 0.09
Nodes (5): LocCredentialsPolicyService, {closure#22}(), {closure#4}(), LocCredentialsXlsImporter, LocCredentialsImportWebFlowTest

### Community 10 - "Importador de personal LOC"
Cohesion: 0.11
Nodes (4): LocImportBatch, LocPersonalXlsImporter, LocGestionController, {closure#1}()

### Community 11 - "Usuarios y autorización"
Cohesion: 0.06
Nodes (16): User, AppServiceProvider, {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}() (+8 more)

### Community 12 - "Incidencias biométricas"
Cohesion: 0.06
Nodes (20): BiometricoIncidenciaSeveridad, ALTA, BAJA, CRITICA, INFO, MEDIA, BiometricoIncidenciaTipo, ARCHIVO_DESAPARECIDO (+12 more)

### Community 13 - "Mapeo de filas de personal"
Cohesion: 0.06
Nodes (10): {closure#1}(), {closure#12}(), {closure#13}(), {closure#15}(), {closure#21}(), {closure#3}(), {closure#5}(), {closure#6}() (+2 more)

### Community 14 - "Ejecuciones de indexación"
Cohesion: 0.06
Nodes (16): {closure#2}(), BiometricoIndexRunEstado, CANCELADA, COMPLETADA, COMPLETADA_CON_INCIDENCIAS, EN_EJECUCION, FALLIDA, INTERRUMPIDA (+8 more)

### Community 15 - "Staging de importaciones"
Cohesion: 0.07
Nodes (8): LocImportStagingRow, {closure#1}(), {closure#10}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#1}()

### Community 16 - "Controladores de importación"
Cohesion: 0.07
Nodes (10): {closure#1}(), {closure#2}(), {closure#1}(), {closure#2}(), {closure#6}(), {closure#1}(), {closure#2}(), {closure#3}() (+2 more)

### Community 18 - "Migraciones de esquema LOC"
Cohesion: 0.09
Nodes (23): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}() (+15 more)

### Community 19 - "Prefiltro de solicitudes"
Cohesion: 0.07
Nodes (9): PrefiltroSourcesMissingException, {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#14}(), {closure#15}(), {closure#9}() (+1 more)

### Community 20 - "Escáner biométrico"
Cohesion: 0.10
Nodes (7): BiometricScannerService, {closure#1}(), BiometricScanEntry, scan(), BiometricScannerServiceTest, {closure#1}(), {closure#2}()

### Community 21 - "Frontend npm y assets"
Cohesion: 0.07
Nodes (28): dependencies, alpinejs@^3.17.5, devDependencies, autoprefixer@^10.4.21, axios@^1.11.0, concurrently@^9.0.1, laravel-vite-plugin@^2.0.0, postcss@^8.5.30 (+20 more)

### Community 26 - "Jobs de importación en cola"
Cohesion: 0.14
Nodes (4): ProcessLocCatalogoImportBatch, ProcessLocCredentialsImportBatch, ProcessLocImportBatch, ProcessLocWeaponsImportBatch

### Community 28 - "Servicio de indexación biométrica"
Cohesion: 0.10
Nodes (4): BiometricIndexingService, BiometricConcurrencyAndLeaseTest, BiometricHierarchicalResumptionTest, BiometricRemediationAudit243Test

### Community 29 - "Rutas web y controladores"
Cohesion: 0.08
Nodes (5): AuthenticatedSessionController, Controller, DashboardController, LocCredentialsImportController, LocWeaponsImportController

### Community 31 - "Importador de armamento"
Cohesion: 0.12
Nodes (4): LocWeaponsOleReuseReader, {closure#3}(), {closure#4}(), LocWeaponsXlsImporter

### Community 33 - "Provisión de superusuario"
Cohesion: 0.11
Nodes (10): {closure#1}(), {closure#2}(), LocSuperuserProvisioner, UserFactory, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}() (+2 more)

### Community 34 - "Ámbito y expedientes biométricos"
Cohesion: 0.11
Nodes (6): BiometricoAmbito, ESTATALES, NO_RESUELTO, PREVENCION, {closure#5}(), {closure#7}()

### Community 35 - "Importación de elementos"
Cohesion: 0.13
Nodes (4): {closure#1}(), {closure#2}(), SolicitudElementosImporter, SolicitudRegistroEImportacionPhaseTwoTwoTest

### Community 36 - "Estados de archivo biométrico"
Cohesion: 0.08
Nodes (14): BiometricoClasificacionEstado, DEFINITIVA, PROVISIONAL, VALIDADA, BiometricoEstadoArchivo, CUARENTENA, DESAPARECIDO, DUPLICADO_SHA (+6 more)

### Community 38 - "Gestión de dispositivos"
Cohesion: 0.16
Nodes (7): {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), DeviceController, Device, DeviceDiagnostics

### Community 39 - "Solicitudes y Form Requests"
Cohesion: 0.09
Nodes (4): SolicitudCredencialController, FilterSolicitudElementosRequest, StoreSolicitudCredencialRequest, UploadSolicitudElementosRequest

### Community 40 - "Normalización de credenciales XLS"
Cohesion: 0.08
Nodes (10): {closure#11}(), {closure#16}(), {closure#18}(), {closure#19}(), {closure#2}(), {closure#3}(), {closure#6}(), {closure#8}() (+2 more)

### Community 41 - "Hash streaming"
Cohesion: 0.13
Nodes (3): StreamingHashCalculator, StreamingHashResult, StreamingHashCalculatorTest

### Community 44 - "Resolver de ámbito biométrico"
Cohesion: 0.18
Nodes (4): BiometricResolverService, BiometricUpdateService, {closure#1}(), BiometricoEsquemaYModelosTest

### Community 48 - "Docker Compose MariaDB"
Cohesion: 0.10
Nodes (20): MARIADB_DATABASE, MARIADB_PASSWORD, MARIADB_ROOT_PASSWORD, MARIADB_USER, interval, retries, start_period, test (+12 more)

### Community 49 - "Migraciones biométricas"
Cohesion: 0.10
Nodes (5): {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}(), {closure#2}()

### Community 51 - "Plantillas de credencial"
Cohesion: 0.23
Nodes (4): {closure#1}(), {closure#3}(), {closure#4}(), CredentialTemplateController

### Community 52 - "Migraciones base"
Cohesion: 0.11
Nodes (5): {closure#1}(), {closure#1}(), {closure#2}(), {closure#1}(), {closure#1}()

### Community 54 - "Docs almacenamiento solo lectura"
Cohesion: 0.16
Nodes (12): BiometricDeliveryService::streamArchivo, BiometricoAuditoria::registrarAcceso (append-only snapshots), BiometricoReadOnlyException, Disco biometricos (safe-local-readonly, serve=false), Etapa 2.4 comando loc:biometricos:indexar (pendiente), Informe Implementacion Etapa 2.3 (Almacenamiento Biometrico Seguro), SafeReadOnlyLocalAdapter, SafeReadOnlyLocalAdapterTest (28 pruebas) (+4 more)

### Community 56 - "Acceso demo local"
Cohesion: 0.18
Nodes (4): LocalDemoAccess, {closure#1}(), {closure#1}(), {closure#2}()

### Community 59 - "Atributos de persona"
Cohesion: 0.24
Nodes (3): {closure#1}(), {closure#3}(), LocPersonalXlsImporter

### Community 60 - "Prototipo OLE armas"
Cohesion: 0.23
Nodes (5): memoryInMiB(), OleReuseXlsReader, PrototypeArmasReadFilter, runReaderMode(), sourceRowIsEmpty()

### Community 62 - "Secuencia de tarjetas"
Cohesion: 0.21
Nodes (3): SequenceNotInitializedException, {closure#4}(), SolicitudTarjetaSequenceService

### Community 63 - "Migraciones prefiltro/ámbito"
Cohesion: 0.15
Nodes (6): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#1}(), {closure#2}()

### Community 64 - "Docs importación de personal"
Cohesion: 0.21
Nodes (8): Migraciones LOC staging 2026_10_21_000002 / 000003, Ciclo de vida asincrono de importacion (pendiente -> procesando -> en_revision), loc_personas.datos_loc (encabezados de origen JSON), Integracion importador de personal autorizado LOC, routes/loc-gestion-routes.php y gestion-upload-card, Importacion del padron de personal LOC (.xls), Tablas loc_import_batches / loc_import_staging_rows, Migracion 2026_10_21_000004 expand CUIP columns to 100

### Community 74 - "Auditorías Etapa 2.2/2.3"
Cohesion: 0.24
Nodes (10): Auditoria Tecnica Independiente - Etapa 2.3, Dictamen Etapa 2.3: Aprobada con observaciones, Auditoria Tecnica de Integridad - Etapa 2.2, Dictamen Etapa 2.2: Aceptacion condicionada a correcciones, BiometricoExpediente::recalcularCompletitud (conteo ciego), Entorno real: Laravel 13.36.0 / PHP 8.4.0 / MariaDB 11.4.13, Indexador CLI loc:biometricos:indexar (generadores, SHA-256, --resume), Prueba de restauracion aislada del respaldo (loc_restore_verification) (+2 more)

### Community 75 - "Docs importación armamento"
Cohesion: 0.20
Nodes (9): Acceso de demostracion local (LOCAL_DEMO_ACCESS, solo lectura), Comando loc:crear-superusuario, Prueba controlada del importador LOC (SQLite aislado), Scripts tools/loc-import-test (Initialize/Verify/Stop-IsolatedTestDb.ps1), Gestion de dispositivos (Zebra, Canon, Topaz, Crossmatch), Importacion de armamento LOC (armamento_loc), LocWeaponsImportTest suite, Migracion 2026_10_22_000001_add_armamento_import_support (+1 more)

### Community 77 - "Reglas del proyecto (CLAUDE.md)"
Cohesion: 0.22
Nodes (5): .claude/CLAUDE.md (graphify skill pointer), graphify skill (/graphify trigger), Laravel Backend Rules, CLAUDE.md - Sistema Estatal LOC 139, Sistema Estatal de Credenciales de Portacion de Armas LOC 139

### Community 78 - "Estado de revisión biométrica"
Cohesion: 0.22
Nodes (5): BiometricoEstadoRevision, APROBADO_TECNICO, EN_ACLARACION, RECHAZADO_CALIDAD, SIN_EVALUAR

### Community 80 - "Vistas credencialización"
Cohesion: 0.22
Nodes (8): modules.credencializacion.detalle-carga-elementos, modules.credencializacion.detalle-elementos, modules.credencializacion.detalle-resumen, modules.credencializacion.detalle-vacio, modules.credencializacion.listado-metricas, modules.credencializacion.listado-tabla, modules.credencializacion.modal-nueva-solicitud, layouts.app

### Community 81 - "Estrategia de blindaje"
Cohesion: 0.25
Nodes (4): Database and Testing Rules, H-05: permisos NTFS de escritura heredados, Plan de permisos NTFS con icacls (pendiente de autorizacion), Diseno SafeReadOnlyLocalAdapter (extiende LocalFilesystemAdapter)

### Community 82 - "Auditoría pericial con snapshots"
Cohesion: 0.25
Nodes (7): Gate view-biometrics (AppServiceProvider), H-02: inmutabilidad append-only solo en eventos Eloquent (proponer triggers MariaDB), BiometricDeliveryService::streamArchivo (10 controles, auditoria previa), Riesgos residuales TOCTOU, biometrico_auditorias con nullOnDelete sin snapshots, Correccion D: snapshots y append-only en BiometricoAuditoria, BiometricoAuditoria::registrarAcceso

### Community 83 - "Cierre Etapa 2.2"
Cohesion: 0.29
Nodes (8): BiometricoAmbito::fromClaveCatalogo (fallback regex), Cierre de Etapa 2.2 y Preparacion de Etapa 2.3, Diseno BiometricDeliveryService (streaming con cabeceras no-store), Disco 'biometricos' (driver safe-local-readonly, serve=false), Migracion 2026_11_04_000005 add_snapshot_columns_to_biometrico_auditorias, Repositorio fisico Biometricos_Estatal (115,406 archivos), BiometricoEsquemaYModelosTest (13 tests, 53 aserciones), Correccion A: clasificacion de ambito sin fallback (fromCatalogoRecord)

### Community 87 - "Hallazgos SafeReadOnlyAdapter"
Cohesion: 0.33
Nodes (6): assertSafePath anti-path traversal (6 filtros), BiometricoReadOnlyException, H-01: constructor no valida location vacia, SafeReadOnlyLocalAdapter (8 mutadores bloqueados, DISALLOW_LINKS), SafeReadOnlyLocalAdapterTest (28 tests, 83 aserciones), Matriz de riesgos R1-R5 (memoria, concurrencia, escritura, path traversal)

### Community 88 - "Migración create users table"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 89 - "Migración create jobs table"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 90 - "Migración add datos loc to loc personas "
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 91 - "Migración add datos loc to loc personas "
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 92 - "Vista de gestión"
Cohesion: 0.33
Nodes (5): modules.gestion-armas-upload-card, modules.gestion-catalogo-upload-card, modules.gestion-credenciales-upload-card, modules.gestion-upload-card, layouts.app

### Community 104 - "Docs workers de producción"
Cohesion: 0.40
Nodes (5): Conexiones de cola dedicadas (loc_import, loc_weapons_import, loc_credentials_import), Guia de automatizacion de workers en produccion, Importacion de credenciales y vigencias LOC, Despliegue loc-worker via Supervisor / Systemd, Prototipo reutilizacion streams OLE (prototype_armas_xls_ole_reuse.php)

### Community 106 - "Vista gestion-armas-import-preview"
Cohesion: 0.40
Nodes (4): modules.partials.gestion-armas-chips, modules.partials.gestion-armas-table, layouts.app, modules.partials.import-preview-ajax

### Community 107 - "Vista gestion-import-preview"
Cohesion: 0.40
Nodes (4): modules.partials.gestion-personal-chips, modules.partials.gestion-personal-table, layouts.app, modules.partials.import-preview-ajax

### Community 108 - "Vista gestion-credenciales-import-preview"
Cohesion: 0.40
Nodes (4): modules.partials.gestion-vigencias-chips, modules.partials.gestion-vigencias-table, layouts.app, modules.partials.import-preview-ajax

### Community 111 - "Asociación solicitud-expediente"
Cohesion: 0.50
Nodes (3): Asociacion convencional solicitud_elementos-biometrico_expedientes, BiometricResolverService (transaccion + lockForUpdate), Correccion B: SolicitudElemento::asociarExpediente y candado saving

### Community 127 - "Expediente técnicamente completo"
Cohesion: 0.67
Nodes (3): Definicion Expediente Tecnicamente Completo (7 condiciones), Correccion C: completitud estructural vs integridad tecnica vs aprobacion humana, BiometricoExpediente::esAptoParaImpresion / esIntegroTecnico

## Knowledge Gaps
- **130 isolated node(s):** `ESTATALES`, `PREVENCION`, `NO_RESUELTO`, `FOTO`, `FIRMA` (+125 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 840 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **134 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `TestCase` connect `Base de pruebas y modelos LOC` to `Entrega segura de biométricos`, `Pruebas importación armamento`, `Pruebas elementos de solicitud`, `Conciliación credenciales-armas`, `Importador de credenciales`, `Usuarios y autorización`, `Ejecuciones de indexación`, `Staging de importaciones`, `Pruebas importación personal`, `Prefiltro de solicitudes`, `Regresión credenciales`, `Componentes visuales compartidos`, `Clasificación de motivos`, `Política de credenciales`, `Servicio de indexación biométrica`, `Importador de catálogo`, `Comando indexar biométricos (tests)`, `Provisión de superusuario`, `Importación de elementos`, `Estados de archivo biométrico`, `Integración indexación biométrica`, `Conciliación de identidad`, `Resolver de ámbito biométrico`, `Catálogo área solicitante`, `Motor de prefiltro (tests)`, `Regresión personal`, `Remediación de prefiltro`, `Editor de plantillas v2`, `Pruebas editor de plantillas`, `Pruebas de dispositivos`, `Pruebas demo local`, `Vista previa y actualización biométrica`, `Pruebas secuencia de tarjetas`, `Infraestructura control biométrico`, `Pruebas de autenticación`, `Pruebas de dashboard`, `Previsualización plantilla v2`, `Integridad de solicitudes`, `Persistencia de negocio`, `Esquema de prefiltro`?**
  _High betweenness centrality (0.106) - this node is a cross-community bridge._
- **Are the 2 inferred relationships involving `LocImportBatch` (e.g. with `.test_import_progress_monitor_renders_pending_state_with_queued_indicator()` and `.test_import_progress_monitor_renders_processing_state_with_metrics_and_stage()`) actually correct?**
  _`LocImportBatch` has 2 INFERRED edges - model-reasoned connections that need verification._
- **What connects `ESTATALES`, `PREVENCION`, `NO_RESUELTO` to the rest of the system?**
  _130 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Modelos Eloquent y relaciones` be split into smaller, more focused modules?**
  _Cohesion score 0.0380952380952381 - nodes in this community are weakly interconnected._
- **Why does `User` connect `Usuarios y autorización` to `Modelos Eloquent y relaciones`, `Entrega segura de biométricos`, `Base de pruebas y modelos LOC`, `Pruebas elementos de solicitud`, `Conciliación credenciales-armas`, `Cola de impresión y resolver`, `Importador de credenciales`, `Ejecuciones de indexación`, `Staging de importaciones`, `Prefiltro de solicitudes`, `Regresión credenciales`, `Clasificación de motivos`, `Servicio de indexación biométrica`, `Importador de catálogo`, `Provisión de superusuario`, `Estados de archivo biométrico`, `Integración indexación biométrica`, `Conciliación de identidad`, `Resolver de ámbito biométrico`, `Catálogo área solicitante`, `Regresión personal`, `Acceso demo local`, `Editor de plantillas v2`, `Pruebas de dispositivos`, `Pruebas demo local`, `Vista previa y actualización biométrica`, `Pruebas secuencia de tarjetas`, `Infraestructura control biométrico`, `Previsualización plantilla v2`, `Seeder de base de datos`?**
  _High betweenness centrality (0.102) - this node is a cross-community bridge._
- **Should `Entrega segura de biométricos` be split into smaller, more focused modules?**
  _Cohesion score 0.055087719298245616 - nodes in this community are weakly interconnected._
- **Why does `LocImportBatch` connect `Importador de personal LOC` to `Modelos Eloquent y relaciones`, `Pruebas importación armamento`, `Base de pruebas y modelos LOC`, `Pruebas elementos de solicitud`, `Importador de credenciales`, `Mapeo de filas de personal`, `Staging de importaciones`, `Controladores de importación`, `Prefiltro de solicitudes`, `Relaciones persona-arma`, `Regresión credenciales`, `Componentes visuales compartidos`, `Clasificación de motivos`, `Jobs de importación en cola`, `Política de credenciales`, `Rutas web y controladores`, `Importador de catálogo`, `Importador de armamento`, `Respuestas HTTP y vistas`, `Normalización de credenciales XLS`, `Conciliación de identidad`, `Lectura XLS armamento por bloques`, `Catálogo área solicitante`, `Regresión personal`, `Relaciones batch-elemento`, `Atributos de persona`, `Controlador LocGestion`?**
  _High betweenness centrality (0.100) - this node is a cross-community bridge._