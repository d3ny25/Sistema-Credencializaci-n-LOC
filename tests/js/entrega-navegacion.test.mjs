// Pruebas de la navegación dinámica de Entrega y Seguimiento con fetch, historial y DOM SIMULADOS.
// Ejecutar: node --test tests/js/entrega-navegacion.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { crearNavegacionEntrega, debeInterceptarEnlace, esRutaDelModulo, mensajeError, urlFormularioGet } from '../../resources/js/entrega-navegacion.js';

const ORIGEN = 'http://loc.test';
const enlace = (href, atributos = {}) => ({
    href: new URL(href, ORIGEN).toString(),
    dataset: atributos.dataset ?? {},
    hasAttribute: (n) => n in atributos,
    getAttribute: (n) => atributos[n] ?? null,
});
const clic = (extra = {}) => ({ button: 0, defaultPrevented: false, ...extra });

function respuesta(estado, cuerpo, { redirigida = false, url = '' } = {}) {
    return {
        ok: estado >= 200 && estado < 300, status: estado, redirected: redirigida, url,
        headers: { get: () => 'application/json' },
        json: async () => cuerpo,
    };
}

function entorno(respuestas) {
    const llamadas = [];
    const historial = [];
    const nodos = Object.fromEntries(['[data-page-heading]', '[data-page-breadcrumb]', '[data-page-subheading]', '[data-page-avisos]'].map((s) => [s, { innerHTML: '' }]));
    const ventana = {
        location: { href: ORIGEN + '/entregas', origin: ORIGEN, pathname: '/entregas', assign: (u) => historial.push(['assign', u]) },
        history: {
            pushState: (_e, _t, u) => { historial.push(['push', u]); ventana.location.pathname = new URL(u).pathname; },
            replaceState: (_e, _t, u) => historial.push(['replace', u]),
        },
        addEventListener: () => {}, removeEventListener: () => {}, scrollTo: () => {},
    };
    const documento = { title: '', querySelector: (s) => nodos[s] ?? null };
    const fetch = async (url, opciones) => { llamadas.push([url, opciones]); return respuestas.shift(); };
    const nav = crearNavegacionEntrega({ fetch, ventana, documento });
    nav.$refs = { contenido: { innerHTML: 'anterior' } };
    return { nav, llamadas, historial, nodos, documento };
}

test('solo intercepta enlaces internos del módulo, nunca archivos, pestañas nuevas ni clics modificados', () => {
    assert.equal(debeInterceptarEnlace(enlace('/entregas/tarjetas/2/pendientes?q=x'), clic(), ORIGEN), true);
    assert.equal(debeInterceptarEnlace(enlace('/entregas'), clic(), ORIGEN), true);
    assert.equal(debeInterceptarEnlace(enlace('/entregas/tarjetas/2/documentos/5/descargar'), clic(), ORIGEN), false);
    assert.equal(debeInterceptarEnlace(enlace('/entregas/tarjetas/2/documentos/5/ver', { target: '_blank' }), clic(), ORIGEN), false);
    assert.equal(debeInterceptarEnlace(enlace('/impresion'), clic(), ORIGEN), false);
    assert.equal(debeInterceptarEnlace(enlace('/entregas-x'), clic(), ORIGEN), false);
    assert.equal(debeInterceptarEnlace(enlace('http://otro.test/entregas'), clic(), ORIGEN), false);
    assert.equal(debeInterceptarEnlace(enlace('/entregas', { download: '' }), clic(), ORIGEN), false);
    assert.equal(debeInterceptarEnlace(enlace('/entregas', { dataset: { navegacion: 'normal' } }), clic(), ORIGEN), false);
    assert.equal(debeInterceptarEnlace(enlace('/entregas'), clic({ ctrlKey: true }), ORIGEN), false);
    assert.equal(debeInterceptarEnlace(enlace('/entregas'), clic({ button: 1 }), ORIGEN), false);
    assert.equal(esRutaDelModulo('/entregas/tarjetas/2/documentos', ORIGEN), true, 'la carga documental (POST) sí es dinámica');
});

test('el prefijo limita la interceptación al módulo que usa el componente (Consultas)', () => {
    assert.equal(debeInterceptarEnlace(enlace('/consultas?q=x&elementos_page=2'), clic(), ORIGEN, '/consultas'), true);
    assert.equal(debeInterceptarEnlace(enlace('/entregas/tarjetas/1'), clic(), ORIGEN, '/consultas'), false, 'salir a otro módulo es navegación normal');
    assert.equal(esRutaDelModulo('/consultas', ORIGEN, '/consultas'), true);
    assert.equal(esRutaDelModulo('/consultas-x', ORIGEN, '/consultas'), false);
});

test('los formularios GET omiten parámetros vacíos y conservan la ruta', () => {
    assert.equal(urlFormularioGet(ORIGEN + '/entregas?pagina=3', [['q', ' TJ-1 '], ['estado', ''], ['archivo', {}]]), ORIGEN + '/entregas?q=TJ-1');
});

test('mensajes de error distinguen sesión, CSRF, permisos y servidor', () => {
    assert.match(mensajeError(419), /expiró/);
    assert.match(mensajeError(401), /sesión/);
    assert.equal(mensajeError(403, { message: 'Sin permiso de entrega.' }), 'Sin permiso de entrega.');
    assert.match(mensajeError(500), /500/);
});

test('una consulta sustituye solo el contenido, actualiza encabezado y avisos y agrega historial', async () => {
    const { nav, llamadas, historial, nodos, documento } = entorno([respuesta(200, {
        html: '<p>tarjeta</p>', encabezado: 'Tarjeta TJ-1', subencabezado: 'Área', avisos: '', titulo: 'Panel · Tarjeta TJ-1 · Sistema', url: ORIGEN + '/entregas/tarjetas/1',
    })]);
    await nav.ir(ORIGEN + '/entregas/tarjetas/1');
    assert.equal(llamadas[0][1].headers['X-Requested-With'], 'XMLHttpRequest');
    assert.equal(nav.$refs.contenido.innerHTML, '<p>tarjeta</p>');
    assert.equal(nodos['[data-page-heading]'].innerHTML, 'Tarjeta TJ-1');
    assert.equal(documento.title, 'Panel · Tarjeta TJ-1 · Sistema');
    assert.deepEqual(historial, [['push', ORIGEN + '/entregas/tarjetas/1']]);
    assert.equal(nav.cargando, false);
});

test('Atrás restaura sin agregar historial', async () => {
    const { nav, historial } = entorno([respuesta(200, { html: 'x', url: ORIGEN + '/entregas' })]);
    await nav.ir(ORIGEN + '/entregas', { historial: false });
    assert.deepEqual(historial, []);
});

test('un error no sustituye el contenido ni simula éxito; 422 conserva el formulario y reactiva su botón', async () => {
    const boton = { disabled: true };
    const formulario = { querySelectorAll: () => [boton] };
    const { nav, historial } = entorno([respuesta(422, { errors: { receptor_nombre: ['El nombre es obligatorio.'] } }), respuesta(403, { message: 'No autorizado.' })]);
    await nav.ir(ORIGEN + '/entregas/tarjetas/1/entregas/confirmar', { metodo: 'POST', cuerpo: {}, formulario });
    assert.equal(nav.$refs.contenido.innerHTML, 'anterior');
    assert.deepEqual(nav.errores, ['El nombre es obligatorio.']);
    assert.equal(boton.disabled, false);
    await nav.ir(ORIGEN + '/entregas/tarjetas/1');
    assert.equal(nav.error, 'No autorizado.');
    assert.equal(nav.$refs.contenido.innerHTML, 'anterior');
    assert.deepEqual(historial, []);
});

test('la confirmación (respuesta directa a POST) no cambia la URL; el registro redirigido sí', async () => {
    const { nav, historial } = entorno([
        respuesta(200, { html: 'confirmar', url: ORIGEN + '/entregas/tarjetas/1/entregas/confirmar' }),
        respuesta(200, { html: 'detalle', url: ORIGEN + '/entregas/tarjetas/1/entregas/7' }, { redirigida: true }),
    ]);
    await nav.ir(ORIGEN + '/entregas/tarjetas/1/entregas/confirmar', { metodo: 'POST', cuerpo: {} });
    assert.deepEqual(historial, []);
    await nav.ir(ORIGEN + '/entregas/tarjetas/1/entregas', { metodo: 'POST', cuerpo: {} });
    assert.deepEqual(historial, [['push', ORIGEN + '/entregas/tarjetas/1/entregas/7']]);
});

test('un envío en curso no se repite y una respuesta no parcial vuelve a la navegación tradicional', async () => {
    let liberar;
    const pendiente = new Promise((r) => { liberar = r; });
    const llamadas = [];
    const ventana = { location: { href: ORIGEN + '/entregas', origin: ORIGEN, pathname: '/entregas', assign: (u) => llamadas.push(['assign', u]) }, history: { pushState() {}, replaceState() {} }, addEventListener() {}, removeEventListener() {}, scrollTo() {} };
    const nav = crearNavegacionEntrega({ fetch: (u) => { llamadas.push(['fetch', u]); return pendiente; }, ventana, documento: { querySelector: () => null } });
    nav.$refs = { contenido: { innerHTML: '' } };
    const formulario = { dataset: {}, action: ORIGEN + '/entregas/tarjetas/1/entregas', getAttribute: () => 'POST', querySelectorAll: () => [] };
    globalThis.FormData = class { entries() { return []; } };
    const evento = { target: formulario, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
    nav.alEnviar(evento);
    nav.alEnviar({ ...evento, defaultPrevented: false, preventDefault() {} });
    assert.equal(llamadas.filter(([t]) => t === 'fetch').length, 1, 'el doble clic no genera un segundo POST');
    liberar({ ok: true, status: 200, redirected: false, url: '', headers: { get: () => 'text/html' }, json: async () => ({}) });
    await pendiente;
    await new Promise((r) => setTimeout(r, 0));
    assert.equal(nav.error, mensajeError(500), 'un POST con respuesta inesperada no se reenvía');
});
