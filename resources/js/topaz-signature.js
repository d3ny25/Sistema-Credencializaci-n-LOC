/**
 * Captura de firma con el pad Topaz SigLite LCD 1x5 mediante el SDK oficial SigWeb (SigWebTablet.js).
 *
 * El SDK se sirve desde public/vendor/topaz/SigWebTablet.js y define funciones globales. Este módulo
 * solo llama a funciones que existan en la versión cargada: si falta alguna requerida, el estado es
 * "sdk_incompatible" y no se intenta capturar. El trazo en vivo lo dibuja el propio SDK en el <canvas>
 * que recibe SetTabletState(1, ctx, intervalo); no hay otro sondeo.
 */

export const FUNCIONES_SDK = {
    requeridas: ['SetTabletState', 'ClearTablet', 'NumberOfTabletPoints', 'GetSigImageB64'],
    // Al menos una de estas confirma que el servicio local SigWeb responde.
    servicio: ['IsSigWebInstalled', 'GetSigWebVersion'],
    opcionales: ['TabletConnectQuery', 'SetDisplayXSize', 'SetDisplayYSize', 'SetJustifyMode', 'SetImageXSize', 'SetImageYSize', 'SetImagePenWidth'],
};

export const MENSAJES = {
    sin_verificar: 'Sin verificar.',
    sdk_ausente: 'El SDK oficial SigWebTablet.js no está instalado en el sistema (public/vendor/topaz/).',
    sdk_incompatible: 'La versión instalada de SigWebTablet.js no ofrece las funciones necesarias.',
    servicio_no_disponible: 'El servicio local SigWeb no responde en esta estación.',
    pad_no_conectado: 'SigWeb responde, pero no detecta ningún pad Topaz conectado.',
    listo: 'Pad listo para capturar.',
    capturando: 'Captura en curso: firme en el pad Topaz.',
    error: 'Error de comunicación con el pad Topaz.',
};

const PNG_FIRMA = [0x89, 0x50, 0x4e, 0x47];

export function crearCapturaTopaz({ sdk = globalThis, intervaloRefresco = 50, tiempoImagenMs = 5000 } = {}) {
    const existe = (nombre) => typeof sdk?.[nombre] === 'function';
    const llamarOpcional = (nombre, ...args) => (existe(nombre) ? sdk[nombre](...args) : undefined);

    const captura = {
        estado: 'sin_verificar',
        mensaje: MENSAJES.sin_verificar,
        version: null,
        elementoId: null,
        faltantes: [],
        temporizador: null,

        fijar(estado, mensaje = MENSAJES[estado]) {
            this.estado = estado;
            this.mensaje = mensaje;
            return estado;
        },

        verificar() {
            if (this.estado === 'capturando') return this.estado;

            const todas = [...FUNCIONES_SDK.requeridas, ...FUNCIONES_SDK.servicio];
            if (!todas.some(existe)) return this.fijar('sdk_ausente');

            this.faltantes = FUNCIONES_SDK.requeridas.filter((f) => !existe(f));
            if (this.faltantes.length > 0 || !FUNCIONES_SDK.servicio.some(existe)) {
                const faltan = [...this.faltantes, ...(FUNCIONES_SDK.servicio.some(existe) ? [] : [FUNCIONES_SDK.servicio.join(' o ')])];
                return this.fijar('sdk_incompatible', `${MENSAJES.sdk_incompatible} Faltan: ${faltan.join(', ')}.`);
            }

            try {
                const instalado = existe('IsSigWebInstalled') ? sdk.IsSigWebInstalled() : true;
                const version = existe('GetSigWebVersion') ? String(sdk.GetSigWebVersion() ?? '').replace(/"/g, '').trim() : '';
                this.version = version || null;
                // Una respuesta sin versión no prueba que el servicio esté disponible.
                if (!instalado || (existe('GetSigWebVersion') && !/^\d+(\.\d+)+$/.test(version))) {
                    return this.fijar('servicio_no_disponible');
                }
            } catch (_) {
                return this.fijar('servicio_no_disponible');
            }

            if (existe('TabletConnectQuery')) {
                try {
                    if (!(Number(sdk.TabletConnectQuery()) > 0)) return this.fijar('pad_no_conectado');
                } catch (_) {
                    return this.fijar('servicio_no_disponible');
                }
                return this.fijar('listo');
            }

            return this.fijar('listo', `${MENSAJES.listo} Esta versión del SDK no permite confirmar la conexión del pad antes de firmar.`);
        },

        iniciar(elementoId, canvas) {
            if (this.estado === 'capturando') throw new Error('Ya hay una captura en curso; acéptela o cancélela primero.');
            if (this.verificar() !== 'listo') throw new Error(this.mensaje);
            if (!elementoId) throw new Error('Seleccione un elemento antes de capturar la firma.');

            const ctx = canvas?.getContext?.('2d');
            if (!ctx) throw new Error('No hay lienzo disponible para mostrar el trazo.');

            try {
                llamarOpcional('SetDisplayXSize', canvas.width);
                llamarOpcional('SetDisplayYSize', canvas.height);
                llamarOpcional('SetJustifyMode', 0);
                sdk.ClearTablet();
                this.temporizador = sdk.SetTabletState(1, ctx, intervaloRefresco);
            } catch (err) {
                this.liberar();
                this.fijar('error', `${MENSAJES.error} ${err?.message ?? ''}`.trim());
                throw new Error(this.mensaje);
            }

            if (!this.temporizador) {
                this.liberar();
                this.fijar('error', 'SigWeb no activó la captura en el pad.');
                throw new Error(this.mensaje);
            }

            this.elementoId = elementoId;
            this.fijar('capturando');
        },

        limpiar(canvas = null) {
            if (this.estado !== 'capturando') return;
            sdk.ClearTablet();
            canvas?.getContext?.('2d')?.clearRect(0, 0, canvas.width, canvas.height);
        },

        async aceptar({ anchoImagen = 600, altoImagen = 200, grosor = 4 } = {}) {
            if (this.estado !== 'capturando') throw new Error('No hay una captura en curso.');

            const puntos = Number(sdk.NumberOfTabletPoints());
            if (!(puntos > 0)) throw new Error('El pad no registró ningún trazo. Firme en el pad antes de aceptar.');

            const elementoId = this.elementoId;
            sdk.SetTabletState(0, this.temporizador);
            this.temporizador = null;
            llamarOpcional('SetImageXSize', anchoImagen);
            llamarOpcional('SetImageYSize', altoImagen);
            llamarOpcional('SetImagePenWidth', grosor);

            try {
                const b64 = await new Promise((resolve, reject) => {
                    const limite = setTimeout(() => reject(new Error('SigWeb no entregó la imagen de la firma a tiempo.')), tiempoImagenMs);
                    sdk.GetSigImageB64((datos) => {
                        clearTimeout(limite);
                        resolve(datos);
                    });
                });
                const blob = base64APng(b64);
                return { blob, elementoId, puntos };
            } catch (err) {
                this.fijar('error', err.message);
                throw err;
            } finally {
                this.liberar();
                if (this.estado !== 'error') this.fijar('listo');
            }
        },

        cancelar() {
            const estabaCapturando = this.estado === 'capturando';
            this.liberar();
            if (estabaCapturando) this.fijar('listo', 'Captura cancelada; no se guardó ninguna firma.');
            return estabaCapturando;
        },

        // Devuelve el pad a reposo y descarta el trazo: se usa al aceptar, cancelar, cambiar de elemento o cerrar la página.
        liberar() {
            try {
                if (this.temporizador) sdk.SetTabletState(0, this.temporizador);
                if (existe('ClearTablet')) sdk.ClearTablet();
            } catch (_) {
                // El pad puede haberse desconectado; no hay más que liberar del lado del navegador.
            }
            this.temporizador = null;
            this.elementoId = null;
        },
    };

    return captura;
}

function base64APng(b64) {
    const limpio = String(b64 ?? '').replace(/^data:image\/\w+;base64,/, '').trim();
    if (limpio.length === 0) throw new Error('SigWeb devolvió una imagen de firma vacía.');

    let binario;
    try {
        binario = atob(limpio);
    } catch (_) {
        throw new Error('SigWeb devolvió una imagen de firma ilegible.');
    }

    const bytes = Uint8Array.from(binario, (c) => c.charCodeAt(0));
    if (!PNG_FIRMA.every((b, i) => bytes[i] === b)) throw new Error('SigWeb no devolvió una imagen PNG.');

    return new Blob([bytes], { type: 'image/png' });
}
