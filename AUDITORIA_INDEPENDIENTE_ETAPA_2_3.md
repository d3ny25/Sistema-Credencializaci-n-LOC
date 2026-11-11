# INFORME DE AUDITORÍA TÉCNICA INDEPENDIENTE — ETAPA 2.3
## Sistema de Credencialización e Impresión de Identificaciones LOC No. 139
### Verificación de almacenamiento biométrico seguro, Flysystem, Laravel y protección de evidencia original

**Fecha de emisión:** 04 de Noviembre de 2026  
**Auditor / Arquitecto Técnico:** Antigravity Senior Engineering (Auditoría Forense y de Seguridad)  
**Modalidad operativa:** MODO ESTRICTO DE SOLO LECTURA  
**Dictamen Técnico:** **APROBADA CON OBSERVACIONES**  

---

## A. RESUMEN EJECUTIVO

Se ha ejecutado una auditoría técnica exhaustiva e independiente sobre la implementación de la **Etapa 2.3 (Configuración de Almacenamiento Seguro y Entrega Biométrica de Solo Lectura)** en el repositorio `sistema-de-credencializacion-loc/laravel-app`.

La auditoría se realizó en **modo estrictamente no destructivo (solo lectura)**, verificando mediante análisis estático, Reflection en tiempo de ejecución, inspección de dependencias y ejecución de pruebas en entornos aislados (SQLite en memoria y directorios temporales).

### Conclusiones Principales:
1. **Existencia y Operatividad Verificadas:** Los cuatro archivos creados (`BiometricoReadOnlyException.php`, `SafeReadOnlyLocalAdapter.php`, `BiometricDeliveryService.php`, `SafeReadOnlyLocalAdapterTest.php`) y los tres modificados (`AppServiceProvider.php`, `config/filesystems.php`, `.env.example`) existen físicamente, compilan sin errores sintácticos y cumplen con la arquitectura diseñada.
2. **Efectividad del Bloqueo de Mutaciones:** Se confirmó empíricamente que el adaptador bloquea incondicionalmente los ocho métodos mutadores de Flysystem (`write`, `writeStream`, `delete`, `deleteDirectory`, `createDirectory`, `setVisibility`, `move`, `copy`) arrojando `BiometricoReadOnlyException`.
3. **Mecanismo Anti-Path Traversal Operativo:** Las rutinas de validación neutralizan bytes nulos, rutas absolutas (Windows/Unix), esquemas URI y retrocesos (`..`), forzando además la constante nativa `DISALLOW_LINKS` para prevenir fugas mediante enlaces simbólicos.
4. **Reproducibilidad de Pruebas:** Se ejecutaron las tres suites de pruebas automatizadas en SQLite en memoria (`SafeReadOnlyLocalAdapterTest`: 28/28, `BiometricoEsquemaYModelosTest`: 13/13, `SolicitudElementosOptimizacionTablaTest`: 13/13), obteniendo un total de **54 pruebas y 231 aserciones con 100% de aprobación**.
5. **Preservación Total de Evidencia y Datos:** Se certificó que los **115,406 archivos biométricos originales** en `Biometricos_Estatal` permanecen intactos sin una sola modificación, y que la base operativa MariaDB `loc_credencializacion` (34,858 personas, 55,983 armas, 480 catálogo, 33 migraciones) no sufrió ninguna alteración.

---

## B. INVENTARIO DE ARCHIVOS

### B.1. Componentes Implementados en la Etapa 2.3

| Archivo | Estado en Repositorio | Líneas | SHA-256 (Primeros 16 caracteres) | Verificación Sintáctica (`php -l`) |
| :--- | :---: | :---: | :---: | :---: |
| `app/Exceptions/BiometricoReadOnlyException.php` | **Existente** | 28 | `784b23d517865f1e...` | OK (0 errores) |
| `app/Support/Storage/SafeReadOnlyLocalAdapter.php` | **Existente** | 228 | `eb42036c0a0c6488...` | OK (0 errores) |
| `app/Services/BiometricDeliveryService.php` | **Existente** | 134 | `6552ff90333792ef...` | OK (0 errores) |
| `tests/Feature/SafeReadOnlyLocalAdapterTest.php` | **Existente** | 487 | `09440f90e5f2998a...` | OK (0 errores) |
| `app/Providers/AppServiceProvider.php` | **Modificado** | 147 | `cf940656cfb8e011...` | OK (0 errores) |
| `config/filesystems.php` | **Modificado** | 88 | `6643dd3a32f627fc...` | OK (0 errores) |
| `.env.example` | **Modificado** | 91 | `eb744d0cb52c42ad...` | OK (0 errores) |

### B.2. Sincronización de Documentación de Referencia

Se auditó la presencia de los 6 documentos técnicos en las 4 ubicaciones clave:
- `AUDITORIA_COMPATIBILIDAD_FASE_2_REVISION_FINAL.md`: Presente en Workspace Root y Residencias (32,372 bytes).
- `AUDITORIA_INTEGRIDAD_ETAPA_2_2.md`: Presente en Workspace Root, Laravel App y Residencias (24,400 bytes).
- `IMPLEMENTACION_CORRECTIVA_ETAPA_2_2.md`: Presente en Workspace Root, Laravel App y Residencias (14,761 bytes).
- `CIERRE_ETAPA_2_2_PREPARACION_ETAPA_2_3.md`: Presente en Workspace Root, Laravel App y Residencias (25,690 bytes).
- `PLAN_IMPLEMENTACION_ETAPA_2_3.md`: Presente en Workspace Root, Laravel App y Residencias (22,183 bytes).
- `IMPLEMENTACION_ETAPA_2_3.md`: Presente en Workspace Root, Laravel App y Residencias (19,770 bytes).

---

## C. COMPATIBILIDAD TÉCNICA

Se contrastaron las declaraciones de versiones con los paquetes reales instalados:

| Componente | Versión Reportada | Versión Real Verificada | Método de Comprobación |
| :--- | :---: | :---: | :--- |
| **PHP** | 8.4.0 | **8.4.0 (cli)** | `php -v` (NTS Visual C++ 2019 x64) |
| **Laravel** | 13.36.0 | **13.36.0** | `php artisan --version` |
| **MariaDB** | 11.4.13 | **11.4.13-MariaDB-ubu2404** | `SELECT VERSION()` en puerto 3308 |
| **Flysystem** | 3.36.0 | **3.36.0** | Inspección en `composer.lock` |
| **Flysystem Local** | 3.35.3 | **3.35.3** | Inspección en `composer.lock` |

### Compatibilidad de Firmas de Flysystem 3:
Mediante Reflection se constató que `SafeReadOnlyLocalAdapter` respeta estrictamente las firmas de `LocalFilesystemAdapter`:
- `__construct`: Respeta los 7 parámetros nativos, forzando `linkHandling = self::DISALLOW_LINKS` y `lazyRootCreation = true`.
- Los 8 métodos mutadores conservan los tipos de retorno `: void` requeridos por la interfaz `FilesystemAdapter`.
- Los métodos de lectura (`read`, `readStream`, `fileExists`, `directoryExists`, `listContents`, `mimeType`, `fileSize`, `lastModified`, `visibility`, `checksum`) delegan de forma compatible tras superar la validación.

---

## D. AUDITORÍA DE SEGURIDAD

### D.1. Blindaje contra Mutaciones en Capa de Adaptador
Se probó la invocación directa de mutadores tanto a través de la interfaz del adaptador como mediante la fachada `Storage::disk('biometricos')`.
- Invocaciones bloqueadas: `write()`, `writeStream()`, `delete()`, `deleteDirectory()`, `createDirectory()`, `setVisibility()`, `move()`, `copy()`, y `Storage::put()`.
- Excepción lanzada: `App\Exceptions\BiometricoReadOnlyException`.
- Comprobación física: Los archivos temporales originales no sufrieron ninguna modificación en contenido, permisos ni marcas de tiempo tras los intentos de mutación.

### D.2. Mecanismos Anti-Path Traversal
El método `assertSafePath()` aplica una serie de filtros secuenciales:
1. **Rechazo de bytes nulos (`\0`):** Previene ataques de envenenamiento de cadena en funciones de C del sistema de archivos.
2. **Normalización de barras:** Convierte todas las barras invertidas `\` a barras diagonales `/`.
3. **Rechazo de esquemas y streams:** Filtra patrones `^[a-zA-Z0-9]+://` bloqueando envoltorios como `file://`, `php://`, `phar://`.
4. **Rechazo de rutas absolutas:** Bloquea unidades de disco de Windows (`^[a-zA-Z]:`) y barras iniciales (`/`), neutralizando también rutas UNC de red (`//server/share`).
5. **Rechazo de segmentos `..`:** Divide la ruta y bloquea cualquier segmento que coincida con `..`.
6. **Resolución canónica y de ancestros:** Si el archivo existe, comprueba que `realpath()` empiece por `$this->canonicalRoot`. Si no existe, recorre recursivamente los directorios antecesores hasta encontrar el directorio padre existente y verifica que resida dentro de la raíz.

---

## E. AUDITORÍA DE AUTORIZACIÓN

### E.1. Evaluación de la Gate `view-biometrics`
En `AppServiceProvider.php` (líneas 40-44):
```php
Gate::define('view-biometrics', static function (User $user) use ($isSuperAdministrator, $isLocalDemo): bool {
    return $isSuperAdministrator($user)
        || $isLocalDemo($user)
        || in_array($user->role, ['superadmin', 'admin', 'supervisor', 'credencializador'], true);
});
```
- **Roles autorizados:** `superadmin`, `admin`, `supervisor`, `credencializador`, así como usuarios en modo demo local (`local_demo_access` activo en entorno `local`).
- **Roles denegados:** Usuarios con rol `null`, `operador`, `consulta_basica`, `armamento` o cualquier cadena no catalogada.
- **Evaluación de sobreautorización:** No existe sobreautorización indiscriminada; el acceso requiere pertenecer explícitamente a uno de los cuatro roles operativos de credencialización o contar con privilegios de superadministrador.

### E.2. Exposición de Rutas HTTP
- Se inspeccionó exhaustivamente `routes/web.php`.
- **Hallazgo:** Actualmente **no existe ninguna ruta HTTP pública ni autenticada** que invoque directamente a `BiometricDeliveryService::streamArchivo()`.
- **Impacto:** Conforme al cronograma arquitectónico (`AUDITORIA_COMPATIBILIDAD_FASE_2_REVISION_FINAL.md`), la exposición del endpoint de streaming forma parte de la **Etapa 2.6**. Esto constituye una salvaguarda positiva en la Etapa 2.3, pues el servicio existe a nivel de lógica de dominio pero no está expuesto en la superficie de ataque web.

---

## F. AUDITORÍA DE INTEGRIDAD Y TRAZABILIDAD

### F.1. Cadena de Validación de `BiometricDeliveryService`
El método `streamArchivo()` impone 10 controles estrictos:
1. Comprueba autenticación del usuario (`$actor->exists && $actor->id !== null`).
2. Comprueba la Gate `view-biometrics`.
3. Comprueba vigencia del archivo (`$archivo->es_vigente === true`).
4. Comprueba estado técnico válido (`$archivo->estado_archivo === VALIDO`).
5. Comprueba concordancia de CUIP y Ámbito entre archivo y expediente (si se proporciona `$expediente`).
6. Valida que la ruta relativa sea segura contra Path Traversal (`assertSafePath()`).
7. Comprueba existencia física en el disco seguro (`fileExists()`).
8. **Registra la auditoría pericial con snapshots antes de retornar el stream.**
9. Detecta el MIME type confiable con cabeceras `nosniff`, `inline` y `no-store`.
10. Abre el flujo binario con `fpassthru()` y cierre garantizado en bloque `finally`.

### F.2. Inmutabilidad y Trazabilidad Histórica de Auditorías
- La tabla `biometrico_auditorias` cuenta con sus 18 columnas y 9 índices/FKs en MariaDB operativa.
- Todas las claves foráneas tienen configurada la acción `ON DELETE SET NULL`. **Ninguna tiene `ON DELETE CASCADE`**.
- Si un usuario, expediente o archivo es eliminado de la base de datos, el registro de auditoría **no se borra**, y las columnas escalares de snapshot (`actor_user_id_historico`, `actor_email_historico`, `cuip_snapshot`, `ambito_snapshot`, `tipo_biometrico_snapshot`, `ruta_relativa_snapshot`, `sha256_snapshot`) preservan la evidencia forense completa de forma inmutable.

---

## G. RESULTADOS DE PRUEBAS AUTOMATIZADAS (PHPUNIT)

Se verificó previamente que `phpunit.xml` utiliza SQLite en memoria (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`), garantizando total aislamiento de la base operativa.

### G.1. `SafeReadOnlyLocalAdapterTest.php`
- **Comando:** `php artisan test tests/Feature/SafeReadOnlyLocalAdapterTest.php`
- **Resultado:** **28 pasadas, 83 aserciones, 0 fallas (1.58s)**
- Cobertura: Lectura (7 tests), Bloqueo de mutaciones (9 tests), Seguridad de rutas y Path Traversal (4 tests), Servicio de entrega y auditoría (8 tests).

### G.2. Suite de No Regresión: `BiometricoEsquemaYModelosTest.php`
- **Comando:** `php artisan test tests/Feature/BiometricoEsquemaYModelosTest.php`
- **Resultado:** **13 pasadas, 53 aserciones, 0 fallas (0.74s)**
- Cobertura: Clasificación de ámbito, candados de asociación CUIP+ámbito, completitud e inmutabilidad de auditoría.

### G.3. Suite de No Regresión: `SolicitudElementosOptimizacionTablaTest.php`
- **Comando:** `php artisan test tests/Feature/SolicitudElementosOptimizacionTablaTest.php`
- **Resultado:** **13 pasadas, 95 aserciones, 0 fallas (1.55s)**
- Cobertura: Tabla de elementos, filtros asíncronos y prefiltro.

### G.4. Balance Consolidado
**Total: 54 pruebas, 231 aserciones, 100% aprobadas sin ninguna regresión.**

---

## H. MATRIZ DE HALLAZGOS TÉCNICOS

| ID | Severidad | Componente / Archivo | Línea / Método | Descripción del Hallazgo | Escenario de Riesgo | Recomendación |
| :-: | :-: | :--- | :--- | :--- | :--- | :--- |
| **H-01** | **MEDIO** | `SafeReadOnlyLocalAdapter.php` | Constructor (L26-29) | No valida si la ruta `$location` es una cadena vacía o nula al instanciar. | Si en `.env` se define `BIOMETRICOS_STORAGE_PATH=""`, `$this->canonicalRoot` quedaría vacío, evaluando rutas relativas contra la raíz de la unidad. | Agregar validación explícita en el constructor: `if (trim($location) === '') throw new InvalidArgumentException(...)`. |
| **H-02** | **MEDIO** | `BiometricoAuditoria.php` | Modelo Eloquent | Inmutabilidad Append-Only implementada únicamente en eventos Eloquent (`updating`/`deleting`). | Consultas directas mediante `DB::table('biometrico_auditorias')->delete()` no disparan eventos Eloquent. | Crear triggers en MariaDB `BEFORE UPDATE` y `BEFORE DELETE` con `SIGNAL SQLSTATE '45000'` en una fase de endurecimiento posterior. |
| **H-03** | **BAJO** | `config/filesystems.php` | Disco `biometricos` (L63-70) | El parámetro `throw` está configurado en `true`, pero en Flysystem 3 el reporte de excepciones es capturado por el framework si no se personaliza. | Comportamiento estándar de Laravel; sin impacto negativo detectado. | Mantener como está; verificado en pruebas. |
| **H-04** | **INFORMATIVO** | `routes/web.php` | Rutas web | `BiometricDeliveryService` no tiene aún un endpoint HTTP enrutado en `web.php`. | No representa una vulnerabilidad; corresponde a la Etapa 2.6 (Streaming Seguro y Vistas). | Programar la vinculación del endpoint en la Etapa 2.6. |
| **H-05** | **INFORMATIVO** | Repositorio Físico | Permisos NTFS | El proceso PHP local (`LAPTOP-A0VTH04F\Daniel`) conserva permisos NTFS heredados de escritura sobre la carpeta física. | El blindaje opera actualmente en la capa de software (Laravel). Un script fuera de Laravel podría escribir en la carpeta. | Ejecutar el procedimiento de aseguramiento con `icacls` en una ventana de mantenimiento autorizada. |

---

## I. ANÁLISIS DE RIESGOS RESIDUALES

### I.1. Riesgos TOCTOU (Time of Check to Time of Use)
- **Escenario 1 (Verificación de existencia vs. Streaming):** En `BiometricDeliveryService`, `fileExists()` se ejecuta en el paso 7, la auditoría se inserta en el paso 8, y la apertura del stream ocurre en el paso 11 dentro del closure de `StreamedResponse`. Si un archivo es eliminado del disco en ese intervalo (microsegundos), la auditoría registrará el intento pero el stream fallará con `UnableToReadFile`.  
  *Impacto:* Bajo. El archivo no se entrega y la traza de auditoría refleja fielmente que un usuario intentó consultar el recurso.
- **Escenario 2 (Validación de ruta vs. Apertura):** `assertSafePath()` valida la ruta antes de delegar a `parent::readStream()`. Dado que `SafeReadOnlyLocalAdapter` fuerza `DISALLOW_LINKS`, Flysystem vuelve a verificar de manera nativa en el momento de la apertura que el destino no sea un enlace simbólico, mitigando el riesgo de sustitución en tiempo de ejecución.

### I.2. Permisos NTFS Fuera de la Capa de Aplicación
- `SafeReadOnlyLocalAdapter` garantiza que **ningún código ejecutado dentro del ecosistema Laravel** pueda alterar los archivos biométricos.
- No obstante, la cuenta de usuario de Windows conserva permisos NTFS de escritura sobre el directorio. El plan de restricción mediante `icacls` diseñado en la Etapa 2.3 permanece pendiente de autorización formal para cerrar este perímetro a nivel de sistema operativo.

---

## J. DIFERENCIAS CON EL INFORME ANTERIOR (`IMPLEMENTACION_ETAPA_2_3.md`)

| Afirmación del Informe Anterior | Estado de Verificación | Comentarios del Auditor |
| :--- | :---: | :--- |
| Creación de `BiometricoReadOnlyException` | **Confirmada al 100%** | Código y herencia conformes. |
| Bloqueo de 8 operaciones mutadoras en `SafeReadOnlyLocalAdapter` | **Confirmada al 100%** | Probado empíricamente; las 8 operaciones lanzan la excepción. |
| Mecanismo Anti-Path Traversal estricto | **Confirmada al 100%** | Bytes nulos, `..`, rutas absolutas y esquemas URI son bloqueados. |
| Registro del disco `'biometricos'` con `serve => false` | **Confirmada al 100%** | Configurado y verificado en `config/filesystems.php`. |
| Gate `'view-biometrics'` en `AppServiceProvider` | **Confirmada al 100%** | Registrada y funcional; restringe a roles calificados. |
| `BiometricDeliveryService` con streaming y auditoría previa | **Confirmada al 100%** | Ejecuta snapshots periciales antes de abrir el stream binario. |
| 28 pruebas y 83 aserciones en `SafeReadOnlyLocalAdapterTest` | **Confirmada al 100%** | Coincidencia exacta con la salida real de PHPUnit. |
| 13 pruebas y 53 aserciones en `BiometricoEsquemaYModelosTest` | **Confirmada al 100%** | Coincidencia exacta con la salida real de PHPUnit. |
| 13 pruebas y 95 aserciones en `SolicitudElementosOptimizacionTablaTest` | **Confirmada al 100%** | Coincidencia exacta con la salida real de PHPUnit. |
| Repositorio original con 115,406 archivos intactos | **Confirmada al 100%** | Conteo verificado: 101,474 Estatales + 13,932 Prevención. |
| Cero migraciones ejecutadas y MariaDB intacta | **Confirmada al 100%** | 33 migraciones registradas; conteos de datos idénticos. |

---

## K. DICTAMEN TÉCNICO

> [!IMPORTANT]
> **DICTAMEN FINAL:** **APROBADA CON OBSERVACIONES**
>
> La implementación de la **Etapa 2.3** cumple rigurosamente con los objetivos de seguridad, arquitectura y preservación de evidencia digital.
>
> **Fundamentación:**
> - El repositorio físico original permanece 100% intacto (115,406 archivos).
> - El adaptador de solo lectura neutraliza de forma demostrable toda mutación y evasión de ruta desde Laravel.
> - La base operativa MariaDB se mantuvo libre de escrituras no autorizadas.
> - La suite de 54 pruebas pasó al 100% sin ninguna regresión.
>
> Las observaciones detectadas (**H-01** y **H-02**) corresponden a mejoras preventivas de robustez que no impiden el funcionamiento seguro del subsistema en el estado actual.

---

## L. PLAN CORRECTIVO PROPUESTO (PARA FASE POSTERIOR)

Si se autoriza atender las observaciones menores:
1. **Atención a H-01:** Agregar validación en `SafeReadOnlyLocalAdapter::__construct()`:
   ```php
   if (trim($location) === '') {
       throw new \InvalidArgumentException("La ruta raíz del repositorio biométrico no puede estar vacía.");
   }
   ```
2. **Atención a H-02:** Programar para la fase de cierre de base de datos la creación de triggers MariaDB para blindar `biometrico_auditorias` contra sentencias SQL directas de actualización o borrado.

---

## M. SOLICITUD FORMAL DE AUTORIZACIÓN

Conforme a la instrucción innegociable de trabajo, **el proceso se detiene en este punto**.

Para avanzar hacia la siguiente etapa del proyecto:
- **Etapa 2.4: Implementación del Comando Indexador CLI (`loc:biometricos:indexar`)**, que contemplará:
  1. Escaneo por streaming con Generadores PHP ($O(1)$ RAM).
  2. Indexación incremental con descarte de archivos sin cambios por `mtime/size`.
  3. Hash SHA-256 nativo por streaming binario.
  4. Detección de archivos modificados y desaparecidos.
  5. Modo reanudable con `--resume` y punto de control.
  6. Población controlada de `biometrico_archivos` y `biometrico_expedientes`.

Quedo a la espera de tu revisión de este informe de auditoría independiente y de tus instrucciones para continuar.
