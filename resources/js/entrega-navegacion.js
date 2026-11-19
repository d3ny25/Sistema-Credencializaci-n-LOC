// Navegación dinámica de Entrega y Seguimiento. Mismo patrón que el detalle de Credencialización
// (detalle-elementos.blade.php): fetch con X-Requested-With, el servidor responde JSON con el HTML ya renderizado,
// se sustituye un contenedor y se sincroniza el historial (pushState / popstate). Sin JavaScript, los enlaces y
// formularios funcionan de forma tradicional. El servidor sigue validando y autorizando todo.

const PREFIJO = '/entregas';

/** URL del mismo origen dentro de /entregas, excluidos los archivos del expediente documental. */
export function esRutaDelModulo(href, origen, prefijo = PREFIJO) {
    let url;
    try {
        url = new URL(href, origen);
    } catch {
        return false;
    }
    if (url.origin !== origen) return false;
    if (url.pathname !== prefijo && !url.pathname.startsWith(prefijo + '/')) return false;
    // Los archivos se ven o descargan con su respuesta propia (rutas protegidas), nunca se insertan como HTML.
    return !/\/documentos\/\d+\//.test(url.pathname);
}

/** Enlaces que se resuelven dentro del módulo; descargas, pestañas nuevas y archivos siguen siendo normales. */
export function debeInterceptarEnlace(enlace, evento, origen, prefijo = PREFIJO) {
    if (evento.defaultPrevented || evento.button !== 0 || evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey) return false;
    if (enlace.hasAttribute('download') || enlace.dataset?.navegacion === 'normal') return false;
    const destino = enlace.getAttribute('target');
    if (destino && destino !== '_self') return false;

    return esRutaDelModulo(enlace.href, origen, prefijo);
}

/** URL de un formulario GET sin parámetros vacíos (como el filtro de Credencialización). */
export function urlFormularioGet(accion, pares) {
    const url = new URL(accion);
    url.search = '';
    for (const [clave, valor] of pares) {
        if (typeof valor !== 'string') continue;
        const limpio = valor.trim();
        if (limpio !== '') url.searchParams.append(clave, limpio);
    }
    return url.toString();
}

/** Mensaje de negocio para una respuesta no exitosa; los errores no se presentan como contenido válido. */
export function mensajeError(estado, datos = {}) {
    switch (estado) {
        case 401: return 'Su sesión terminó. Recargue la página e inicie sesión de nuevo.';
        case 403: return datos.message || 'No cuenta con autorización para realizar esta acción.';
        case 404: return 'El recurso solicitado no existe o no pertenece a esta tarjeta.';
        case 413: return 'El archivo excede el tamaño que admite el servidor.';
        case 419: return 'La página expiró por seguridad. Recargue la página para continuar; no se guardó ningún cambio.';
        case 422: return 'Revise los datos indicados.';
        default: return `Error del servidor (${estado}). No se guardó ningún cambio pendiente; intente de nuevo.`;
    }
}

// prefijo: módulo cuyos enlaces se resuelven dinámicamente (Entrega y Seguimiento por defecto; Consultas usa '/consultas').
export function crearNavegacionEntrega({ fetch: fetchImpl = globalThis.fetch?.bind(globalThis), ventana = globalThis.window, documento = globalThis.document, prefijo = PREFIJO } = {}) {
    return {
        cargando: false,
        error: null,
        errores: [],
        control: null,
        alRegresar: null,

        init() {
            // Marca la entrada inicial para poder restaurarla con Atrás.
            ventana.history.replaceState({ entrega: true }, '', ventana.location.href);
            this.alRegresar = (evento) => {
                if (evento.state?.entrega) this.ir(ventana.location.href, { historial: false });
            };
            ventana.addEventListener('popstate', this.alRegresar);
        },

        destroy() {
            ventana.removeEventListener('popstate', this.alRegresar);
        },

        alClic(evento) {
            const enlace = evento.target.closest?.('a[href]');
            if (!enlace || !debeInterceptarEnlace(enlace, evento, ventana.location.origin, prefijo)) return;
            evento.preventDefault();
            this.ir(enlace.href);
        },

        alEnviar(evento) {
            const formulario = evento.target;
            if (evento.defaultPrevented || formulario.dataset?.navegacion === 'normal') return;
            if (!esRutaDelModulo(formulario.action, ventana.location.origin, prefijo)) return;
            evento.preventDefault();
            const metodo = (formulario.getAttribute('method') || 'GET').toUpperCase();
            if (metodo === 'GET') {
                this.ir(urlFormularioGet(formulario.action, new FormData(formulario).entries()));
                return;
            }
            // Una escritura en curso no se repite ni se cancela: el servidor la registra una sola vez (idempotencia).
            if (this.cargando) return;
            this.ir(formulario.action, { metodo, cuerpo: new FormData(formulario), formulario });
        },

        async ir(url, { metodo = 'GET', cuerpo = null, historial = true, formulario = null } = {}) {
            // Solo las consultas se cancelan entre sí (la más reciente gana); una escritura nunca se aborta.
            const propio = metodo === 'GET' ? new AbortController() : null;
            if (propio) {
                this.control?.abort();
                this.control = propio;
            }
            this.cargando = true;
            this.error = null;
            this.errores = [];
            let respuesta;
            try {
                respuesta = await fetchImpl(url, {
                    method: metodo,
                    body: cuerpo,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                    signal: propio?.signal,
                });
                const esJson = (respuesta.headers.get('Content-Type') || '').includes('application/json');
                const datos = esJson ? await respuesta.json() : {};
                if (!respuesta.ok) {
                    this.error = mensajeError(respuesta.status, datos);
                    this.errores = Object.values(datos.errors ?? {}).flat();
                    this.reactivar(formulario);
                    return;
                }
                if (typeof datos.html !== 'string') {
                    // Respuesta inesperada (no parcial): navegación tradicional como alternativa segura.
                    if (metodo === 'GET') ventana.location.assign(url);
                    else this.error = mensajeError(500);
                    return;
                }
                const destino = datos.url || respuesta.url || url;
                const cambiaDeVista = new URL(destino, ventana.location.origin).pathname !== ventana.location.pathname;
                this.aplicar(datos);
                // Una página de confirmación (respuesta directa a un POST) no cambia la URL: su URL no es consultable.
                if (historial && (metodo === 'GET' || respuesta.redirected)) {
                    ventana.history.pushState({ entrega: true }, '', destino);
                }
                if (cambiaDeVista || metodo !== 'GET') ventana.scrollTo?.({ top: 0 });
            } catch (e) {
                if (e?.name === 'AbortError') return;
                if (metodo === 'GET') {
                    ventana.location.assign(url);
                    return;
                }
                this.error = 'No fue posible comunicarse con el servidor. Verifique en el historial si la operación se registró antes de reintentarla.';
                this.reactivar(formulario);
            } finally {
                // Una consulta reemplazada por otra no apaga el indicador de la vigente.
                if (propio === null || this.control === propio) this.cargando = false;
            }
        },

        aplicar(datos) {
            this.$refs.contenido.innerHTML = datos.html;
            const poner = (selector, valor) => {
                const nodo = documento.querySelector(selector);
                if (nodo && typeof valor === 'string') nodo.innerHTML = valor;
            };
            poner('[data-page-heading]', datos.encabezado);
            poner('[data-page-breadcrumb]', datos.encabezado);
            poner('[data-page-subheading]', datos.subencabezado);
            poner('[data-page-avisos]', datos.avisos);
            if (typeof datos.titulo === 'string') documento.title = datos.titulo;
            this.$refs.contenido.focus?.({ preventScroll: true });
        },

        reactivar(formulario) {
            formulario?.querySelectorAll('[type=submit]').forEach((boton) => { boton.disabled = false; });
        },
    };
}
