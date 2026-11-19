/**
 * Seguimiento de un trabajo de impresión en la cola de Windows mediante el agente de estación (solo lectura).
 * El navegador imprime la vista de snapshot; el agente no recibe contenido ni comandos: con el ticket que Laravel
 * emitió para la preparación, informa el estado de la cola Zebra y del documento "LOC139-<trabajo>".
 * Ningún estado de la cola equivale a la confirmación física, que sigue siendo del operador.
 */

export const MENSAJES_COLA = {
    sin_verificar: 'Cola sin verificar.',
    agente_no_disponible: 'Sin seguimiento: el agente de la estación no responde. Elija la impresora Zebra en el diálogo.',
    lista: 'Impresora Zebra lista según Windows.',
    cola_no_encontrada: 'Windows no tiene la cola de la impresora Zebra configurada.',
    impresora_desconectada: 'La impresora Zebra no está conectada a este equipo.',
    fuera_de_linea: 'Windows indica que la impresora está fuera de línea.',
    pausada: 'La cola de la impresora está en pausa.',
    error: 'La impresora informa un error.',
    requiere_atencion: 'La impresora requiere atención (puerta abierta o intervención).',
};

export const MENSAJES_TRABAJO = {
    no_encontrado: 'El trabajo aún no aparece en la cola de la impresora Zebra.',
    en_cola: 'Windows recibió el trabajo y está en cola.',
    imprimiendo: 'Windows indica que el trabajo se está imprimiendo.',
    completado_en_cola: 'Windows marcó el trabajo como terminado.',
    salio_de_cola: 'El trabajo salió de la cola de Windows.',
    error: 'La cola informa un error en el trabajo.',
    eliminado: 'El trabajo se eliminó de la cola.',
    en_otra_cola: 'El trabajo se envió a otra impresora, no a la Zebra.',
};

export const AVISO_CONFIRMACION = 'Esto no confirma la tarjeta física: revísela y registre el resultado.';

const TERMINALES = ['completado_en_cola', 'salio_de_cola', 'eliminado', 'en_otra_cola'];

export function crearSeguimientoImpresion({ agenteUrl, fetch: fetchImpl = globalThis.fetch?.bind(globalThis), tiempoMs = 5000, intervaloMs = 1500, esperaMaximaMs = 180000, esperaAparicionMs = 30000 } = {}) {
    const base = String(agenteUrl || '').replace(/\/+$/, '') + '/v1/impresion';

    async function llamar(ruta, { metodo = 'GET', cabeceras = {} } = {}) {
        const control = new AbortController();
        const limite = setTimeout(() => control.abort(), tiempoMs);
        let respuesta;
        try {
            respuesta = await fetchImpl(base + ruta, { method: metodo, headers: cabeceras, signal: control.signal, cache: 'no-store' });
        } catch (_) {
            const error = new Error(MENSAJES_COLA.agente_no_disponible);
            error.codigo = 'agente_no_disponible';
            throw error;
        } finally {
            clearTimeout(limite);
        }
        let datos = {};
        try { datos = await respuesta.json(); } catch (_) { /* sin JSON */ }
        if (!respuesta.ok) {
            const error = new Error(datos.mensaje || `El agente respondió con HTTP ${respuesta.status}.`);
            error.codigo = datos.codigo || 'error';
            throw error;
        }
        return datos;
    }

    const seguimiento = {
        sesion: null,
        documento: null,
        cola: null,
        simulacion: false,
        temporizador: null,

        /** Estado de la impresora según Windows; nunca lanza: un agente caído es "agente_no_disponible". */
        async verificar() {
            try {
                const datos = await llamar('/estado');
                this.cola = datos.cola ?? null;
                this.simulacion = datos.simulacion === true;
                const estado = MENSAJES_COLA[datos.estado] ? datos.estado : 'error';
                return { estado, mensaje: MENSAJES_COLA[estado], cola: this.cola, alertas: datos.alertas ?? [], simulacion: this.simulacion, presente: datos.dispositivo_presente === true };
            } catch (error) {
                return { estado: 'agente_no_disponible', mensaje: error.codigo === 'agente_no_disponible' ? MENSAJES_COLA.agente_no_disponible : error.message, cola: null, alertas: [], simulacion: false, presente: false };
            }
        },

        /** Abre el seguimiento con el ticket de Laravel; devuelve el nombre de documento que debe usar el trabajo. */
        async abrir(ticket, trabajoEsperado) {
            const datos = await llamar('/sesiones', { metodo: 'POST', cabeceras: { Authorization: `Bearer ${ticket}` } });
            this.sesion = datos.sesion;
            // El documento lo deriva el agente del ticket: debe ser el del trabajo que autorizó Laravel.
            if (datos.documento !== `LOC139-${trabajoEsperado}`) {
                await this.cerrar();
                throw new Error('El agente devolvió un identificador de trabajo distinto; se canceló el seguimiento.');
            }
            this.documento = datos.documento;
            this.simulacion = datos.simulacion === true;
            return this.documento;
        },

        async consultar() {
            const datos = await llamar('/trabajo', { cabeceras: { 'X-Sesion': this.sesion } });
            return { estado: datos.estado, job_id: datos.job_id ?? null, cola: datos.cola ?? null, alertas: datos.alertas ?? [], simulacion: datos.simulacion === true };
        },

        /**
         * Consulta la cola hasta un estado final o el tiempo máximo; avisa solo cuando cambia el estado.
         * Si el trabajo nunca aparece, termina con "no_encontrado" (¿se eligió otra impresora o se canceló el diálogo?).
         */
        seguir(alCambio) {
            clearTimeout(this.temporizador);
            const inicio = Date.now();
            let anterior = null;
            let visto = false;
            const ciclo = async () => {
                if (!this.sesion) return;
                let info;
                try {
                    info = await this.consultar();
                } catch (error) {
                    alCambio({ estado: 'sin_seguimiento', mensaje: error.message, final: true });
                    return;
                }
                if (info.estado !== 'no_encontrado') visto = true;
                const agotado = Date.now() - inicio > esperaMaximaMs || (!visto && Date.now() - inicio > esperaAparicionMs);
                const final = TERMINALES.includes(info.estado) || agotado;
                const clave = `${info.estado}|${info.job_id}`;
                if (clave !== anterior || final) {
                    anterior = clave;
                    alCambio({ ...info, mensaje: MENSAJES_TRABAJO[info.estado] ?? info.estado, final });
                }
                if (!final) this.temporizador = setTimeout(ciclo, intervaloMs);
            };
            ciclo();
        },

        async cerrar() {
            clearTimeout(this.temporizador);
            const sesion = this.sesion;
            this.sesion = null;
            if (!sesion) return;
            try {
                await llamar('/sesiones', { metodo: 'DELETE', cabeceras: { 'X-Sesion': sesion } });
            } catch (_) {
                // El agente caduca la sesión por inactividad.
            }
        },
    };

    return seguimiento;
}
