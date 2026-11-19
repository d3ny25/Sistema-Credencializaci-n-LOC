# Agente de estación biométrica

Proceso local de Windows que da a la vista de impresión acceso a periféricos que el navegador no puede controlar:
la cámara **Canon EOS Rebel T6** y el lector **Cross Match Verifier 300 LC 2.0**. Cada dispositivo tiene su propio
estado, candado y sesión. Laravel conserva permisos, validación, almacenamiento, versionado y auditoría.

| Dispositivo | Rutas | Categorías del ticket | Imagen |
|---|---|---|---|
| Cámara | `/v1/sesiones`, `/v1/vista-previa`, `/v1/fotograma`, `/v1/captura` | `foto` | JPEG |
| Lector | `/v1/huella/estado`, `/v1/huella/sesiones`, `/v1/huella/vista-previa`, `/v1/huella/fotograma`, `/v1/huella/captura` | `pulgar_derecho`, `pulgar_izquierdo` | PNG gris |

`GET /v1/estado` informa ambos dispositivos sin sondear el lector; `GET /v1/huella/estado` sí lo inicializa y lo libera.

## Impresora Zebra ZXP Series 7: monitor de cola de solo lectura

| Rutas | Ticket | Qué hace |
|---|---|---|
| `GET /v1/impresion/estado` | — | Cola configurada (controlador, puerto, trabajos), presencia física y alertas |
| `POST /v1/impresion/sesiones` | `impresion` | Abre el seguimiento del documento `LOC139-<nonce del ticket>` |
| `GET /v1/impresion/trabajo` | sesión | Estado de ese documento en la cola |
| `DELETE /v1/impresion/sesiones` | sesión | Cierra el seguimiento |

**El agente no imprime.** No recibe contenido, rutas, comandos ni nombres de impresora: el navegador imprime la vista
de snapshot de Laravel (`@page 54mm 86mm`, la plantilla aprobada) con el controlador de Windows, y el título de la
página, que es el nombre del documento en la cola, es `LOC139-<trabajo>`, sin datos personales. Así la cola de
Windows nunca queda expuesta a peticiones del navegador.

- Cola y presencia: `System.Printing` (`LocalPrintServer`, `PrintQueue`) y WMI `Win32_PnPEntity`, que solo enumera
  dispositivos presentes. Comprobado en la estación (15-11-2026): cola `Zebra ZXP Series 7 USB Card Printer`,
  controlador del mismo nombre (`zxps7.inf`, 3.2.9.0 = DZ7CG.03.02.09.00), puerto `USB001`; con la impresora
  desconectada el agente informa `impresora_desconectada`. `PrintQueue.IsOffline` NO se activa en ese caso aunque
  WMI marque `WorkOffline`: por eso la presencia se decide con PnP.
- Estados del trabajo: `no_encontrado`, `en_cola`, `imprimiendo`, `completado_en_cola`, `salio_de_cola`, `error`,
  `eliminado`, `en_otra_cola` (p. ej. se eligió "Microsoft Print to PDF", que hoy es la predeterminada del equipo).
  Windows retira los trabajos terminados; si el trabajo ya se vio y desaparece se informa `salio_de_cola`.
  **Ninguno confirma la tarjeta física**: el operador sigue registrando el resultado.
- Configuración opcional: `colaImpresora` y `patronPnpImpresora` (por defecto, los valores comprobados).
- Simulación: `--simulado-impresora[=lista|desconectada|error|otra-cola|sin-trabajo]`.

## Arquitectura

```
Navegador (sesión Laravel)
  1. POST /impresion/elementos/{id}/captura-ticket  → Laravel firma un ticket (elemento, usuario, "foto", 10 min)
  2. POST http://127.0.0.1:47391/v1/sesiones        → el agente verifica la firma y consume el ticket
  3. vista previa / fotograma / captura              → imágenes en memoria, nunca en disco
  4. POST /impresion/elementos/{id}/actualizar-biometrico (+ captura_ticket)
                                                     → Laravel valida la imagen y consume el ticket
```

- Escucha solo en `127.0.0.1`. Rechaza cualquier `Host` distinto de `127.0.0.1:<puerto>` o `localhost:<puerto>` (DNS rebinding) y cualquier `Origin` distinto de `origenPermitido`.
- Ticket: `base64url(JSON {v,n,e,u,c,x})` + `.` + `base64url(HMAC-SHA256)`, con el secreto de la estación (`ESTACION_AGENTE_SECRETO` en Laravel = `secretoEstacion` aquí). Cada ticket se acepta una vez en el agente y una vez en Laravel.
- No guarda credenciales de Laravel ni imágenes. La sesión de cámara se cierra sola tras 60 s sin uso.
- Proceso x86 (.NET Framework 4.8): el EDSDK de Canon y el SDK de Cross Match son de 32 bits.

## Compilar y ejecutar

```
agente-estacion\compilar.cmd                      (usa el csc de .NET Framework de Windows)
copy agente.config.ejemplo.json bin\agente.config.json   (y poner el secreto real)
bin\AgenteEstacion.exe                            (sin SDK: informa "sdk_no_instalado")
bin\AgenteEstacion.exe --simulado                 (SIMULACIÓN: imágenes rotuladas, nunca reales)
bin\AgenteEstacion.exe --simulado=desconectada    (o falla-captura)
bin\AgenteEstacion.exe --simulado-lector          (lector SIMULADO; también =desconectado, =en-uso, =falla-captura)
```

La interfaz no permite guardar una imagen del modo simulación. Además, el PNG simulado del lector lleva un fragmento
`tEXt` "Simulacion" que Laravel rechaza en cualquier carga, también como archivo manual.

## Lector Cross Match: SDK verificado

SDK encontrado en el equipo: Cross Match USB SDK 5.4.0.1 (`USB_SDK\USB4XX.dll`, x86, **sin firma digital**;
dependencias `intl.dll` y `libglib-2.0-0.dll`, x86, sin firma). El controlador `cmt_usb.inf` 6.1.7600.16385 sí está
firmado por Microsoft (WHQL). Configure su carpeta en `rutaSdkCrossMatch`; el agente la carga con `SetDllDirectory`
y comprueba con `GetProcAddress` que exporte cada función antes de usarla (si falta alguna: `sdk_incompatible`).

Funciones usadas, todas declaradas en `USB_SDK\include\USB4XX.H` y en el ejemplo oficial `Sample\CSharpSample`:

| Función | Uso |
|---|---|
| `USB_RegisterCallbacks` | Primera llamada (exigido por USB4XX.H); conexión/desconexión |
| `USB_SetDetectFirstFinger` | Como el ejemplo: detecta dedo o cristal sucio al inicializar |
| `USB_Initialize(0, 0, NULL)` | Tamaño por defecto, sin dibujo automático; un reintento si devuelve 30 (dedo presente) |
| `USB_GetProductCode` | `0x30` = `USB_VERIFIER_300LC2` |
| `USB_SetLiveMode` / `USB_GetLiveImage` | Vista previa |
| `USB_SetLiveMode(false)` / `USB_GetStillImage` | Captura a resolución completa |
| `USB_Dispose` | Libera memoria, hilos y devoluciones de llamada al terminar cada sesión |

No se usa `USB_SaveImage`: escribe un BMP en disco. La imagen (`USB_IMAGE.lpBMI`, DIB de 8 bits con paleta de grises)
se copia y se convierte a PNG en memoria. Todas las llamadas al SDK se ejecutan en un único hilo, con 15 s de espera.

**Comprobado sin lector conectado (14-15/11/2026):** la DLL carga en el proceso x86, exporta todas las funciones y
`USB_RegisterCallbacks` devuelve `USB_NOT_READY` en unos 3 s, que el agente informa como `desconectado`.

**Pendiente de validar con el lector físico:** inicialización real y código de producto; vista previa con
`USB_GetLiveImage` sin bucle de mensajes de Windows; orientación y tamaño de la imagen fija; ciclos repetidos
`Initialize`/`Dispose`; detección de desconexión durante una sesión; convivencia con Fingerprint Capture
(estado 37, `en_uso`).

## Punto de integración pendiente: EDSDK oficial

`ICamara` (`src/Camara.cs`) es el contrato. Falta un adaptador `CamaraEdsdk` sobre el **EDSDK oficial de Canon**.
No se incluyó porque:

- En el equipo no hay ningún EDSDK instalado. El que se usó en la prueba de Live View del 13-11-2026 venía dentro de un paquete de terceros (`inPhoto Capture SLR.rar`), sin firma, y no se considera verificado.
- No se ha confirmado en documentación de Canon que la EOS Rebel T6 esté soportada por la versión que se obtenga.

Al obtener el EDSDK por el programa de desarrolladores de Canon:

1. Confirmar en su lista de modelos la compatibilidad de la EOS Rebel T6 y que la DLL sea x86.
2. Implementar `CamaraEdsdk : ICamara` con las funciones de esa versión. La secuencia que funcionó en la prueba (activar Live View, salida a PC, descargar el fotograma) debe reproducirse con las firmas del SDK oficial, no copiarse de la prueba.
3. `Capturar` devuelve el fotograma de Live View (`origen = "liveview"`); en la prueba, el disparo sin tarjeta SD falló con `0x8D07`.
4. Seleccionarlo por defecto en `Programa.Main` cuando la DLL esté presente.
