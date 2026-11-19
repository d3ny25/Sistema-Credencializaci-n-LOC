// Pruebas del adaptador del lector Cross Match con un fetch SIMULADO: no prueban el lector ni el agente reales.
// Ejecutar: node --test tests/js/huella-agente.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { crearCapturaHuella } from '../../resources/js/canon-agente.js';

const PNG = new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 1, 2]);
const JPEG = new Uint8Array([0xff, 0xd8, 0xff, 0xe0]);

function respuesta(estado, cuerpo, cabeceras = {}) {
    const binario = cuerpo instanceof Uint8Array;
    return {
        ok: estado >= 200 && estado < 300,
        status: estado,
        headers: { get: (n) => cabeceras[n] ?? null },
        json: async () => (binario ? JSON.parse('x') : cuerpo),
        blob: async () => new Blob([binario ? cuerpo : JSON.stringify(cuerpo)]),
    };
}

function agente({ lector = 'conectado', detalle = null, simulacion = false, categoriaSesion = null, falla = {}, imagen = PNG } = {}) {
    const llamadas = [];
    const estados = [];
    const fetch = async (url, { method = 'GET', headers = {} } = {}) => {
        const ruta = new URL(url).pathname;
        llamadas.push(`${method} ${ruta}`);
        if (falla[ruta] === 'red') throw new TypeError('Failed to fetch');
        if (falla[ruta]) return respuesta(falla[ruta].http, falla[ruta].cuerpo);
        if (ruta === '/v1/huella/estado') return respuesta(200, { estado: lector, detalle, simulacion, modelo: 'Cross Match Verifier 300 LC 2.0' });
        if (ruta === '/v1/huella/sesiones' && method === 'POST') {
            const [, elemento, categoria] = headers.Authorization.replace(/^Bearer /, '').split(':');
            return respuesta(201, { sesion: 'h1', elemento_id: Number(elemento), categoria: categoriaSesion ?? categoria, simulacion });
        }
        if (ruta === '/v1/huella/sesiones' && method === 'DELETE') return respuesta(200, { cerrada: true });
        assert.equal(headers['X-Sesion'], 'h1');
        if (ruta === '/v1/huella/vista-previa') return respuesta(200, { vista_previa: true });
        if (ruta === '/v1/huella/fotograma' || ruta === '/v1/huella/captura') return respuesta(200, imagen, { 'X-Simulacion': simulacion ? '1' : '0', 'X-Captura-Origen': 'lector' });
        return respuesta(404, { codigo: 'no_encontrado' });
    };
    return { fetch, llamadas, estados };
}

function nuevo(a, extra = {}) {
    const vistos = [];
    const captura = crearCapturaHuella({
        agenteUrl: 'http://127.0.0.1:47391',
        fetch: a.fetch,
        obtenerTicket: async (elemento, categoria) => { vistos.push(captura.estado); return { ticket: `t:${elemento}:${categoria}` }; },
        ...extra,
    });
    captura.vistos = vistos;
    return captura;
}

test('agente no disponible y estados del SDK se distinguen entre sí', async () => {
    assert.equal(await nuevo(agente({ falla: { '/v1/huella/estado': 'red' } })).verificar(), 'agente_no_disponible');
    assert.equal(await nuevo(agente({ lector: 'sdk_no_instalado' })).verificar(), 'sdk_no_instalado');
    const incompatible = nuevo(agente({ lector: 'sdk_incompatible', detalle: 'USB4XX.dll no exporta: USB_GetStillImage.' }));
    assert.equal(await incompatible.verificar(), 'sdk_incompatible');
    assert.match(incompatible.mensaje, /USB_GetStillImage/);
});

test('lector desconectado, en uso o con error de inicialización no permite capturar', async () => {
    for (const [lector, esperado] of [['desconectado', 'lector_desconectado'], ['en_uso', 'en_uso'], ['error_inicializacion', 'error_inicializacion']]) {
        const captura = nuevo(agente({ lector }));
        assert.equal(await captura.verificar(), esperado);
        await assert.rejects(captura.iniciar(7, 'pulgar_derecho'));
    }
});

test('iniciar pasa por "inicializando" y liga la captura al elemento y a la categoría', async () => {
    const a = agente();
    const captura = nuevo(a);
    await captura.iniciar(7, 'pulgar_izquierdo');
    assert.deepEqual(captura.vistos, ['inicializando']);
    assert.equal(captura.estado, 'vista_previa');

    const resultado = await captura.capturar();
    assert.equal(resultado.elementoId, 7);
    assert.equal(resultado.categoria, 'pulgar_izquierdo');
    assert.equal(resultado.blob.type, 'image/png');
    assert.equal(resultado.simulacion, false);
    assert.equal(captura.estado, 'capturada');
});

test('una sesión abierta para otra categoría se cancela', async () => {
    const a = agente({ categoriaSesion: 'pulgar_derecho' });
    const captura = nuevo(a);
    await assert.rejects(captura.iniciar(7, 'pulgar_izquierdo'), /otro elemento o componente/);
    assert.ok(a.llamadas.includes('DELETE /v1/huella/sesiones'));
    assert.equal(captura.categoria, null);
});

test('error del SDK al inicializar se informa y no deja sesión abierta', async () => {
    const a = agente({ falla: { '/v1/huella/sesiones': { http: 409, cuerpo: { codigo: 'en_uso', mensaje: 'Otra aplicación está usando el lector' } } } });
    const captura = nuevo(a);
    await assert.rejects(captura.iniciar(7, 'pulgar_derecho'), /Otra aplicación/);
    assert.equal(captura.estado, 'en_uso');
    assert.equal(captura.sesion, null);
});

test('imagen que no es PNG o error del SDK en la captura nunca es un éxito', async () => {
    const noPng = nuevo(agente({ imagen: JPEG }));
    await noPng.iniciar(7, 'pulgar_derecho');
    await assert.rejects(noPng.capturar(), /PNG válida/);
    assert.equal(noPng.estado, 'error');

    const falla = nuevo(agente({ falla: { '/v1/huella/captura': { http: 502, cuerpo: { codigo: 'error_sdk', mensaje: 'USB_GetStillImage devolvió el estado 7' } } } }));
    await falla.iniciar(7, 'pulgar_derecho');
    await assert.rejects(falla.capturar(), /USB_GetStillImage/);
});

test('desconexión durante la vista previa detiene el ciclo como lector desconectado', async () => {
    const captura = nuevo(agente({ falla: { '/v1/huella/fotograma': { http: 503, cuerpo: { codigo: 'desconectado', mensaje: 'El lector se desconectó.' } } } }));
    await captura.iniciar(7, 'pulgar_derecho');
    captura.vigilar(() => assert.fail('no debe llegar ningún fotograma'));
    await new Promise((r) => setTimeout(r, 20));
    assert.equal(captura.estado, 'lector_desconectado');
});

test('cancelar libera la sesión del lector y deja el estado "cancelado"', async () => {
    const a = agente();
    const captura = nuevo(a);
    await captura.iniciar(7, 'pulgar_derecho');
    await captura.capturar();
    assert.equal(await captura.cancelar(), true);
    assert.equal(captura.estado, 'cancelado');
    assert.equal(captura.ticket, null);
    assert.ok(a.llamadas.includes('DELETE /v1/huella/sesiones'));
});

test('una huella del modo simulación queda marcada como simulación', async () => {
    const captura = nuevo(agente({ simulacion: true }));
    assert.equal(await captura.verificar(), 'lista');
    assert.match(captura.mensaje, /SIMULACIÓN/);
    await captura.iniciar(7, 'pulgar_derecho');
    assert.equal((await captura.capturar()).simulacion, true);
});
