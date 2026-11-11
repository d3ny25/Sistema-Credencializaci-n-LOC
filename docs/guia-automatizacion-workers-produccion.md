# Guía de Automatización y Operación de Workers en Producción (LOC 139)

Esta guía documenta la arquitectura de colas, el ciclo de vida asíncrono y los procedimientos de despliegue para los procesos de importación masiva del Sistema de Credencialización LOC (Personal, Catálogo Organizacional, Armamento y Vigencias de Credenciales).

---

## 1. Ciclo de Vida Asíncrono Desatendido

El sistema está diseñado para que el usuario web final tenga una experiencia 100% desatendida y reactiva:

```
[ Usuario Web ] 
       │ 1. Carga archivo .xls / .xlsx
       ▼
[ Controlador Web (LocGestionController / LocCredentialsImportController / etc.) ]
       │ 2. Pre-validación rápida: tamaño, mime, extensión, hash SHA-256 (anti-duplicados)
       │ 3. Creación de lote en `loc_import_batches` con estado 'pendiente'
       │ 4. Almacenamiento seguro temporal en `storage/app/loc-imports/...`
       │ 5. Despacho asíncrono de Job a la cola correspondiente
       ▼
[ Base de Datos / Tabla `jobs` ] ◄── (Retorno HTTP inmediato con redirección al monitor)
       ▲
       │ 6. Lectura desatendida por el worker
[ Worker de Colas (`php artisan queue:work ...`) ]
       │ 7. Transición atómica de lote a 'procesando'
       │ 8. Lectura por bloques en streaming (chunks de 5,000 filas vía OLE / Spout)
       │ 9. Sanitización de caracteres, trim y normalización de fechas/identificadores
       │ 10. Clasificación registral en staging (`loc_import_staging_rows`):
       │     - 'nuevo': Elementos no existentes en el padrón
       │     - 'actualizar': Elementos existentes con cambios detectados
       │     - 'sin_cambios': Elementos idénticos al padrón vigente
       │     - 'conflicto' / 'rechazado': Incidencias que requieren atención
       │ 11. Limpieza de archivo temporal en disco y cómputo de huella de revisión
       │ 12. Actualización de lote a 'en_revision'
       ▼
[ Usuario Web en Pantalla de Vista Previa ]
       │ 13. Visualización de estadísticas, filtros por acción y búsqueda contextual
       │ 14. Confirmación de lote: aplicación atómica en base operativa
```

---

## 2. Variables de Entorno y Configuración de Colas

### `.env` en Producción
```env
# Conexión principal de colas
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=3900

# Importación de Personal LOC
LOC_IMPORT_QUEUE_CONNECTION=loc_import
LOC_IMPORT_QUEUE=loc-imports
LOC_IMPORT_MAX_KB=81920
LOC_IMPORT_READ_CHUNK=5000
LOC_IMPORT_WRITE_CHUNK=250

# Importación de Armamento LOC
LOC_WEAPONS_IMPORT_QUEUE_CONNECTION=loc_weapons_import
LOC_WEAPONS_IMPORT_QUEUE=loc-weapons-imports
LOC_WEAPONS_IMPORT_READ_CHUNK=5000
LOC_WEAPONS_IMPORT_WRITE_CHUNK=100
LOC_WEAPONS_IMPORT_RETRY_AFTER=3900
LOC_WEAPONS_IMPORT_REUSE_OLE=true

# Importación de Credenciales y Vigencias LOC
LOC_CREDENTIALS_IMPORT_QUEUE_CONNECTION=loc_credentials_import
LOC_CREDENTIALS_IMPORT_QUEUE=loc-credentials-imports
LOC_CREDENTIALS_IMPORT_READ_CHUNK=5000
LOC_CREDENTIALS_IMPORT_WRITE_CHUNK=100
LOC_CREDENTIALS_IMPORT_RETRY_AFTER=3900
LOC_CREDENTIALS_IMPORT_REUSE_OLE=true
```

> **Nota de Arquitectura:** En `config/queue.php`, las conexiones dedicadas (`loc_import`, `loc_weapons_import`, `loc_credentials_import`) tienen configurado `after_commit => true` y tiempos de `retry_after` extendidos (3900 segundos / 65 minutos) para evitar que un lote extenso sea re-encolado accidentalmente mientras el worker lo está procesando activamente.

---

## 3. Despliegue de Workers en Producción

### Opción A: Supervisor (Recomendado para servidores Linux Debian/Ubuntu/RHEL)

Copiar la plantilla desde `deployment/loc-worker.conf` a `/etc/supervisor/conf.d/loc-worker.conf`:

```ini
[program:loc-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/sistema-de-credencializacion-loc/laravel-app/artisan queue:work --queue=default,loc_imports,loc-imports,loc_credentials,loc-credentials-imports,loc_weapons,loc-weapons-imports --sleep=3 --tries=1 --timeout=3600 --memory=512
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/loc-worker.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=5
stopwaitsecs=3600
```

**Comandos de activación:**
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start loc-worker:*
sudo supervisorctl status
```

---

### Opción B: Systemd (Servicio nativo de Linux)

Copiar la plantilla desde `deployment/loc-worker.service` a `/etc/systemd/system/loc-worker.service`:

```ini
[Unit]
Description=Worker de Colas para Importaciones Masivas LOC 139
After=network.target mysql.service mariadb.service redis.service
Requires=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/sistema-de-credencializacion-loc/laravel-app
ExecStart=/usr/bin/php /var/www/sistema-de-credencializacion-loc/laravel-app/artisan queue:work --queue=default,loc_imports,loc-imports,loc_credentials,loc-credentials-imports,loc_weapons,loc-weapons-imports --sleep=3 --tries=1 --timeout=3600 --memory=512
Restart=always
RestartSec=5
TimeoutStopSec=3600
StandardOutput=append:/var/log/loc-worker.log
StandardError=append:/var/log/loc-worker.error.log
KillSignal=SIGTERM
SendSIGKILL=yes
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
```

**Comandos de activación:**
```bash
sudo systemctl daemon-reload
sudo systemctl enable loc-worker
sudo systemctl start loc-worker
sudo systemctl status loc-worker
```

---

## 4. Alternativa para Entornos Locales de Desarrollo

En máquinas locales o estaciones de desarrollo donde no se desee o no se pueda mantener activo un proceso worker en segundo plano, se puede configurar:

```env
QUEUE_CONNECTION=sync
```

### Comportamiento con `QUEUE_CONNECTION=sync`:
- Al presionar **Subir archivo**, PHP procesará el lote inmediatamente dentro de la misma petición HTTP.
- **Ventaja:** No requiere abrir una terminal separada ejecutando `php artisan queue:work`.
- **Consideraciones:** 
  - Solo apto para archivos de prueba pequeños (< 1,000 registros).
  - En archivos institucionales grandes (+10,000 filas), la petición HTTP superará el `max_execution_time` del servidor web (`504 Gateway Time-out` o error de timeout en PHP).
  - Para pruebas locales con archivos reales, se recomienda mantener `QUEUE_CONNECTION=database` y arrancar temporalmente en una terminal:
    ```bash
    php artisan queue:work --queue=default,loc_imports,loc-imports,loc_credentials,loc-credentials-imports,loc_weapons,loc-weapons-imports --sleep=3 --tries=1 --timeout=3600 --memory=512
    ```

---

## 5. Mantenimiento y Actualizaciones en Producción

Cada vez que se despliegue una nueva versión del código en producción (ej. mediante `git pull` o script de CI/CD), se debe reiniciar ordenadamente los workers para que carguen el código PHP actualizado en memoria:

```bash
php artisan queue:restart
```
El comando `queue:restart` envía una señal de terminación elegante a los workers al finalizar su trabajo actual, permitiendo que Supervisor o Systemd los vuelvan a iniciar automáticamente sin interrumpir transacciones en vuelo.

