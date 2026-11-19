/**
 * Captura con periféricos del agente local de la estación (agente-estacion/): cámara Canon y lector Cross Match.
 * El navegador pide a Laravel un ticket de un solo uso (elemento + categoría), abre con él una sesión en el agente
 * (127.0.0.1), muestra la vista previa, captura y devuelve la imagen para subirla por el flujo biométrico existente.
 * Nunca da por conectado un dispositivo: el estado sale de lo que responde el agente.
 */

const MENSAJES_COMUNES = {
    sin_verificar: 'Sin verificar.',
    agente_no_disponible: 'El agente de la estación no responde en este equipo.',
    agente_no_configurado: 'La captura con el agente de estación no está configurada en el servidor.',
    inicializando: 'Inicializando el dispositivo…',
    vista_previa: 'Vista previa activa.',
    error: 'Error de comunicación con el agente de la estación.',
};

export const MENSAJES_CANON = {
    ...MENSAJES_COMUNES,
    sdk_no_instalado: 'El agente responde, pero no tiene un SDK de Canon verificado instalado.',
    camara_desconectada: 'El agente responde, pero no detecta la cámara Canon conectada.',
    lista: 'Cámara lista.',
    capturada: 'Fotografía capturada: revísela antes de usarla.',
};

export const MENSAJES_HUELLA = {
    ...MENSAJES_COMUNES,
    sdk_no_instalado: 'El agente responde, pero no tiene configurado el SDK de Cross Match (USB4XX.dll).',
    sdk_incompatible: 'El SDK de Cross Match instalado no puede usarse con el agente.',
    lector_desconectado: 'El agente responde, pero no detecta el lector Cross Match conectado.',
    en_uso: 'Otra aplicación está usando el lector; ciérrela y reintente.',
    error_inicializacion: 'El lector no se pudo inicializar.',
    lista: 'Lector disponible.',
    capturada: 'Huella capturada: revísela antes de usarla.',
    cancelado: 'Captura cancelada; no se guardó ninguna huella.',
};

const FIRMAS = {
    jpeg: { mime: 'image/jpeg', bytes: [0xff, 0xd8, 0xff], nombre: 'JPEG' },
    png: { mime: 'image/png', bytes: [0x89, 0x50, 0x4e, 0x47], nombre: 'PNG' },
};

const EN_SESION = ['vista_previa', 'capturada'];

/**
 * @param {object} op
 * @param {string} op.prefijo        '' para la cámara (/v1/...), '/huella' para el lector (/v1/huella/...)
 * @param {(datos: object) => object} op.dispositivoDe  extrae {estado, modelo, simulacion} de la respuesta de estado
 * @param {Record<string, string>} op.estados  estado del agente → estado de la interfaz
 * @param {Record<string, string>} op.porCodigo código de error del agente → estado de la interfaz
 */
export function crearCapturaAgente({
    agenteUrl, obtenerTicket, fetch: fetchImpl = globalThis.fetch?.bind(globalThis), tiempoMs = 4000, intervaloMs = 250,
    prefijo = '', dispositivoDe, estados, porCodigo = {}, mensajes, formato = 'jpeg', estadoTrasCancelar = 'lista',
    conInicializacion = false, mensajeSimulacion, mensajeCancelado = null,
} = {}) {
    const base = String(agenteUrl || '').replace(/\/+$/, '') + '/v1' + prefijo;
    const firma = FIRMAS[formato];
    const codigos = { agente_no_disponible: 'agente_no_disponible', agente_no_configurado: 'agente_no_configurado', ...porCodigo };

    async function llamar(ruta, { metodo = 'GET', cabeceras = {}, limiteMs = tiempoMs } = {}) {
        const control = new AbortController();
        const limite = setTimeout(() => control.abort(), limiteMs);
        try {
            return await fetchImpl(base + ruta, { method: metodo, headers: cabeceras, signal: control.signal, cache: 'no-store' });
        } catch (_) {
            const error = new Error(mensajes.agente_no_disponible);
            error.codigo = 'agente_no_disponible';
            throw error;
        } finally {
            clearTimeout(limite);
        }
    }

    async function errorDe(respuesta) {
        let datos = {};
        try { datos = await respuesta.json(); } catch (_) { /* sin cuerpo JSON */ }
        const error = new Error(datos.mensaje || datos.message || `El agente respondió con HTTP ${respuesta.status}.`);
        error.codigo = datos.codigo || 'error';
        return error;
    }

    async function imagenDe(respuesta) {
        if (!respuesta.ok) throw await errorDe(respuesta);
        const blob = await respuesta.blob();
        const cabecera = new Uint8Array(await blob.slice(0, firma.bytes.length).arrayBuffer());
        if (blob.size === 0 || !firma.bytes.every((b, i) => cabecera[i] === b)) {
            const error = new Error(`El agente no entregó una imagen ${firma.nombre} válida.`);
            error.codigo = 'imagen_invalida';
            throw error;
        }
        return new Blob([blob], { type: firma.mime });
    }

    const captura = {
        estado: 'sin_verificar',
        mensaje: mensajes.sin_verificar,
        simulacion: false,
        modelo: null,
        sesion: null,
        elementoId: null,
        categoria: null,
        ticket: null,
        temporizador: null,

        fijar(estado, mensaje = mensajes[estado]) {
            this.estado = estado;
            this.mensaje = mensaje ?? mensajes.error;
            return estado;
        },

        // Convierte un error del agente en el estado que le corresponde.
        registrarError(error) {
            this.fijar(codigos[error.codigo] || 'error', error.message);
            return error;
        },

        async verificar() {
            if (EN_SESION.includes(this.estado)) return this.estado;
            try {
                // El lector se inicializa y libera al verificarse, lo que puede tardar varios segundos.
                const respuesta = await llamar('/estado', { limiteMs: conInicializacion ? 20000 : tiempoMs });
                if (!respuesta.ok) throw await errorDe(respuesta);
                const dispositivo = dispositivoDe(await respuesta.json()) || {};
                this.simulacion = dispositivo.simulacion === true;
                this.modelo = dispositivo.modelo ?? null;
                const estado = estados[dispositivo.estado];
                if (!estado) return this.fijar('error', 'El agente devolvió un estado de dispositivo desconocido.');
                const mensaje = estado === 'lista' && this.simulacion ? mensajeSimulacion : (dispositivo.detalle && estado !== 'lista' ? dispositivo.detalle : mensajes[estado]);
                return this.fijar(estado, mensaje);
            } catch (error) {
                this.registrarError(error);
                return this.estado;
            }
        },

        async iniciar(elementoId, categoria = null) {
            if (EN_SESION.includes(this.estado)) throw new Error('Ya hay una captura en curso; termínela o cancélela primero.');
            if (!elementoId) throw new Error('Seleccione un elemento antes de capturar.');
            if ((await this.verificar()) !== 'lista') throw new Error(this.mensaje);

            try {
                if (conInicializacion) this.fijar('inicializando');
                const { ticket } = await obtenerTicket(elementoId, categoria);
                const respuesta = await llamar('/sesiones', { metodo: 'POST', cabeceras: { Authorization: `Bearer ${ticket}` }, limiteMs: conInicializacion ? 20000 : tiempoMs });
                if (!respuesta.ok) throw await errorDe(respuesta);
                const datos = await respuesta.json();
                this.sesion = datos.sesion;
                // El agente devuelve el elemento y la categoría firmados en el ticket: deben ser los de esta captura.
                if (Number(datos.elemento_id) !== Number(elementoId) || (categoria !== null && datos.categoria !== categoria)) {
                    await this.cancelar();
                    throw new Error('El agente abrió la sesión para otro elemento o componente; se canceló la captura.');
                }
                this.simulacion = datos.simulacion === true;
                this.elementoId = elementoId;
                this.categoria = categoria;
                this.ticket = ticket;
                await this.activarVistaPrevia();
            } catch (error) {
                if (this.sesion) await this.cancelar();
                throw this.registrarError(error);
            }
        },

        async activarVistaPrevia() {
            const respuesta = await llamar('/vista-previa', { metodo: 'POST', cabeceras: { 'X-Sesion': this.sesion } });
            if (!respuesta.ok) throw await errorDe(respuesta);
            this.fijar('vista_previa', this.simulacion ? 'SIMULACIÓN: vista previa generada por el agente, no por un dispositivo.' : mensajes.vista_previa);
        },

        // Pide fotogramas mientras dure la vista previa; se detiene sola al capturar, cancelar o fallar.
        vigilar(alFotograma) {
            clearTimeout(this.temporizador);
            const ciclo = async () => {
                if (this.estado !== 'vista_previa') return;
                try {
                    alFotograma(await imagenDe(await llamar('/fotograma', { cabeceras: { 'X-Sesion': this.sesion } })));
                } catch (error) {
                    if (this.estado === 'vista_previa') {
                        this.registrarError(error);
                        return;
                    }
                }
                if (this.estado === 'vista_previa') this.temporizador = setTimeout(ciclo, intervaloMs);
            };
            ciclo();
        },

        async capturar() {
            if (this.estado !== 'vista_previa') throw new Error('Inicie la vista previa antes de capturar.');
            clearTimeout(this.temporizador);
            const { elementoId, categoria } = this;
            try {
                const respuesta = await llamar('/captura', { metodo: 'POST', cabeceras: { 'X-Sesion': this.sesion } });
                const blob = await imagenDe(respuesta);
                const origen = respuesta.headers?.get?.('X-Captura-Origen') || null;
                const simulacion = this.simulacion || respuesta.headers?.get?.('X-Simulacion') === '1';
                this.fijar('capturada', simulacion ? 'SIMULACIÓN: esta imagen no proviene de un dispositivo y no puede guardarse.' : mensajes.capturada);
                return { blob, elementoId, categoria, ticket: this.ticket, simulacion, origen };
            } catch (error) {
                throw this.registrarError(error);
            }
        },

        // Repetir reutiliza la sesión abierta: el ticket de Laravel sigue sin usarse hasta guardar.
        async repetir() {
            if (this.estado !== 'capturada') return;
            try {
                await this.activarVistaPrevia();
            } catch (error) {
                throw this.registrarError(error);
            }
        },

        async cancelar(mensaje = null) {
            clearTimeout(this.temporizador);
            const sesion = this.sesion;
            const habiaSesion = sesion !== null;
            this.sesion = null;
            this.elementoId = null;
            this.categoria = null;
            this.ticket = null;
            if (habiaSesion) {
                try {
                    await llamar('/sesiones', { metodo: 'DELETE', cabeceras: { 'X-Sesion': sesion } });
                } catch (_) {
                    // Si el agente ya no responde, su propio tiempo de inactividad libera el dispositivo.
                }
            }
            if (EN_SESION.includes(this.estado) || this.estado === 'inicializando') {
                this.fijar(estadoTrasCancelar, mensaje ?? mensajeCancelado ?? mensajes[estadoTrasCancelar]);
            }
            return habiaSesion;
        },
    };

    return captura;
}

/** Cámara Canon EOS: rutas /v1/*, fotograma JPEG. */
export function crearCapturaCanon(opciones = {}) {
    return crearCapturaAgente({
        ...opciones,
        prefijo: '',
        dispositivoDe: (datos) => ({ ...(datos.camara || {}), simulacion: datos.simulacion }),
        estados: { sdk_no_instalado: 'sdk_no_instalado', desconectada: 'camara_desconectada', conectada: 'lista' },
        porCodigo: { sdk_no_instalado: 'sdk_no_instalado', desconectada: 'camara_desconectada' },
        mensajes: MENSAJES_CANON,
        formato: 'jpeg',
        estadoTrasCancelar: 'lista',
        mensajeSimulacion: 'SIMULACIÓN: el agente no está conectado a ninguna cámara física.',
        mensajeCancelado: 'Captura cancelada; no se guardó ninguna fotografía.',
    });
}

/** Lector Cross Match: rutas /v1/huella/*, imagen PNG en escala de grises, inicialización del SDK al abrir sesión. */
export function crearCapturaHuella(opciones = {}) {
    return crearCapturaAgente({
        ...opciones,
        prefijo: '/huella',
        dispositivoDe: (datos) => datos,
        estados: {
            sin_verificar: 'sin_verificar', sdk_no_instalado: 'sdk_no_instalado', sdk_incompatible: 'sdk_incompatible',
            desconectado: 'lector_desconectado', en_uso: 'en_uso', error_inicializacion: 'error_inicializacion', conectado: 'lista',
        },
        porCodigo: {
            sdk_no_instalado: 'sdk_no_instalado', sdk_incompatible: 'sdk_incompatible', desconectado: 'lector_desconectado',
            en_uso: 'en_uso', error_inicializacion: 'error_inicializacion',
        },
        mensajes: MENSAJES_HUELLA,
        formato: 'png',
        estadoTrasCancelar: 'cancelado',
        conInicializacion: true,
        mensajeSimulacion: 'SIMULACIÓN: el agente no está conectado a ningún lector físico.',
    });
}
