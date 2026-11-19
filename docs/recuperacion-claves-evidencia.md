# Evidencia de impresión: custodia de claves, recuperación e infraestructura

Fase 1B.2. Este documento no contiene ni debe contener claves, contraseñas ni datos personales.

## 1. Qué protege cada elemento

| Elemento | Dónde vive | Protección | Qué se pierde si falta |
|---|---|---|---|
| Biométricos preservados | Disco privado `biometricos_evidencia` (`storage/app/biometricos_evidencia/sha256/…`) | Hash SHA-256 verificado al leer; atributo de solo lectura; triggers de solo inserción | La evidencia biométrica de las emisiones |
| Datos variables de cada preparación | `credencial_preparaciones.datos_variables_cifrados` | `Crypt` de Laravel (AES-256-CBC + HMAC) con `APP_KEY` | Los datos impresos de cada credencial si se pierde la clave |
| Hash de los datos en claro | `credencial_preparaciones.datos_variables_sha256` | Se calcula antes de cifrar; prueba la integridad tras descifrar | — |
| Plantilla y manifiesto | `plantilla_config_canonica`, `manifiesto_canonico` (texto canónico exacto) | Hash de los bytes guardados | — |

Los biométricos preservados **no están cifrados** en la aplicación (decisión institucional pendiente). Su confidencialidad depende del control de acceso y, si se adopta, del cifrado del volumen.

## 2. Custodia de `APP_KEY` (obligatoria antes de producción)

1. Respaldar `APP_KEY` en un medio cifrado fuera del servidor (gestor de secretos institucional o archivo cifrado con dos custodios). Nunca en Git, correo, chat ni capturas.
2. Registrar en la bitácora de infraestructura la fecha de cada respaldo y quién lo custodia, sin escribir la clave.
3. Verificar el respaldo al menos una vez por periodo con el procedimiento de la sección 4.

**Si se pierden `APP_KEY` y todas sus claves anteriores, los datos variables cifrados son irrecuperables.** Los biométricos preservados y los hashes siguen siendo legibles.

## 3. Rotación de clave

1. Respaldar la clave actual (sección 2).
2. Generar la nueva clave en el servidor (`php artisan key:generate --show`) sin mostrarla en pantallas compartidas.
3. Mover la clave actual a `APP_PREVIOUS_KEYS` (separadas por comas si hay varias) y poner la nueva en `APP_KEY`.
4. Limpiar la caché de configuración si se usa (`php artisan config:clear` o volver a `config:cache`).
5. Comprobar la recuperación (sección 4).

Laravel descifra con `APP_KEY` y, si falla, con cada clave de `APP_PREVIOUS_KEYS` (`config/app.php`, `previous_keys`). **Mientras no exista una herramienta de recifrado, las claves anteriores deben conservarse indefinidamente**: retirar una clave anterior hace ilegibles las preparaciones cifradas con ella.

## 4. Prueba de recuperación

- Automatizada, con claves sintéticas generadas por la propia prueba y sin datos reales: `CredencialPreparacionTest::test_datos_variables_se_recuperan_tras_rotar_app_key_con_claves_anteriores`. Cifra con la clave A, rota a B con A como anterior y descifra; sin A falla.
- En un entorno real, abrir una preparación (`/impresion/preparaciones/{uuid}`) después de rotar. La vista descifra los datos y verifica su hash; un error indica una clave anterior faltante.

## 5. Respaldo y restauración

- La base de datos y `storage/app/biometricos_evidencia` se respaldan **juntos**: las filas de `biometrico_evidencias` referencian archivos por su hash.
- Tras restaurar, ejecutar `php artisan credenciales:conciliar-evidencias --integridad` (solo informe). Debe terminar sin registros faltantes, de tamaño discrepante, ilegibles ni con hash discrepante.
- Dos niveles de comprobación:
  - **Normal (predeterminado):** existencia, tamaño y archivos huérfanos. No lee el contenido: es una verificación **estructural**, barata, apta para programarse con frecuencia. No detecta una alteración que conserve el tamaño.
  - **`--integridad`:** además recalcula el SHA-256 de cada archivo y lo compara con el registrado. Es la verificación **criptográfica**; lee todo el almacén, así que conviene programarla en horario de baja carga (p. ej., semanal) y siempre tras restaurar un respaldo.
- Con `--aplicar` expira preparaciones vencidas y borra solo temporales `.part` abandonados; nunca modifica ni borra archivos publicados ni registros de evidencia. Registros y archivos se recorren por lotes.

## 5b. Entregas de evidencia y borrado de usuarios

- `credencial_preparacion_entregas` registra, por preparación, cada una de las cuatro evidencias del snapshot servidas correctamente a la vista de impresión. Confirmar "impresa" exige las cuatro. Es un control técnico de consistencia: no prueba que el operador observara las imágenes ni que la impresora produjera la tarjeta; la confirmación sigue siendo una declaración del operador.
- Un usuario que preparó una impresión o registró una rectificación **no puede borrarse** (clave foránea `restrict`, igual en SQLite y MariaDB): debe desactivarse. Con `SET NULL`, SQLite lo bloquearía por los triggers y MariaDB anularía la columna en silencio, porque las acciones en cascada no disparan triggers.
- `credencial_impresiones.usuario_id` (migración anterior, `nullOnDelete`) conserva la divergencia: SQLite rechaza el borrado por el trigger de solo inserción y MariaDB anularía la columna. Cambiarla exige una decisión sobre la política de baja de usuarios.

## 6. Recomendaciones de infraestructura (no implementadas)

Comprobado en una MariaDB 11.4 aislada y desechable:

- La cuenta creada por la imagen Docker (`MARIADB_USER`) recibe `ALL PRIVILEGES` sobre su base: puede crear y **eliminar** triggers. Los triggers de solo inserción frenan errores de la aplicación y de operadores, pero no a quien tenga privilegio `TRIGGER`, `DROP` o `ALTER`.
- Se recomienda separar:
  - **Cuenta de migraciones:** DDL + `TRIGGER`, usada solo al desplegar.
  - **Cuenta de ejecución:** `SELECT, INSERT` en `biometrico_evidencias`, `credencial_preparacion_biometricos`, `credencial_impresiones` y `credencial_impresion_rectificaciones`; `UPDATE` solo en las columnas de ciclo de vida de `credencial_preparaciones` (`estado`, `cancelada_at`, `cancelada_por_user_id`, `motivo_cancelacion`, `expirada_at`, `primera_vista_at`, `updated_at`) y en las columnas de resolución de `biometrico_incidencias`. Una cuenta así recibió `UPDATE command denied` y `TRIGGER command denied` en la prueba aislada.
- Limitaciones verificadas:
  - `TRUNCATE` no dispara triggers: vació una tabla con triggers de solo inserción. Las tablas referenciadas por claves foráneas (p. ej. `biometrico_evidencias`) sí rechazan `TRUNCATE`.
  - Los triggers se ejecutan con el `DEFINER` que los creó; si esa cuenta se elimina, dejan de funcionar.
  - Las acciones en cascada de claves foráneas no disparan triggers (este esquema usa `restrict`).
  - En SQLite (pruebas), una reconstrucción de tabla por `->change()` podría eliminar los triggers de esa tabla: no alterar estas tablas con `change()` sin recrear sus protecciones.
- Sistema de archivos: dar a la cuenta que ejecuta PHP permiso de crear y leer en `biometricos_evidencia`, sin "Eliminar" ni "Escribir datos" sobre archivos existentes (ACL de NTFS con `OWNER RIGHTS`). El atributo de solo lectura que aplica la aplicación no basta: el propio proceso puede quitarlo.
- El almacén debe estar en un volumen local: la publicación usa `link()`, que exige el mismo volumen NTFS. No se validó sobre recursos compartidos SMB.
