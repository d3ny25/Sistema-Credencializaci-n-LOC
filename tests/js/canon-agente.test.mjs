// Pruebas del adaptador del agente de estación con un fetch SIMULADO: no prueban la cámara ni el agente reales.
// Ejecutar: node --test tests/js/canon-agente.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { crearCapturaCanon } from '../../resources/js/canon-agente.js';

const JPEG = new Uint8Array([0xff, 0xd8, 0xff, 0xe0, 1, 2, 3, 0xff, 0xd9]);

function respuesta(estado, cuerpo, cabeceras = {}) {
    const esJson = !(cuerpo instanceof Uint8Array);
    return {
        ok: estado >= 200 && estado < 300,
        status: estado,
        headers: { get: (n) => cabeceras[n] ?? null },
        json: async () => (esJson ? cuerpo : JSON.parse('x')),
        blob: async () => new Blob([esJson ? JSON.stringify(cuerpo) : cuerpo], { type: esJson ? 'application/json' : 'image/jpeg' }),
    };
}

function agenteSimulado({ camara = 'conectada', simulacion = false, elementoSesion = null, falla = {} } = {}) {
    const llamadas = [];
    const fetch = async (url, { method = 'GET', headers = {} } = {}) => {
        const ruta = new URL(url).pathname;
        llamadas.push(`${method} ${ruta}`);
        if (falla[ruta] === 'red') throw new TypeError('Failed to fetch');
        if (falla[ruta]) return respuesta(falla[ruta].http, falla[ruta].cuerpo);
        if (ruta === '/v1/estado') return respuesta(200, { agente: 'ok', simulacion, camara: { estado: camara, modelo: 'EOS' } });
        if (ruta === '/v1/sesiones' && method === 'POST') {
            assert.match(headers.Authorization, /^Bearer t-\d+$/);
            return respuesta(201, { sesion: 's1', elemento_id: elementoSesion ?? Number(headers.Authorization.split('-')[1]), simulacion });
        }
        if (ruta === '/v1/sesiones' && method === 'DELETE') return respuesta(200, { cerrada: true });
        assert.equal(headers['X-Sesion'], 's1');
        if (ruta === '/v1/vista-previa') return respuesta(200, { vista_previa: true });
        if (ruta === '/v1/fotograma' || ruta === '/v1/captura') return respuesta(200, JPEG, { 'X-Captura-Origen': 'liveview', 'X-Simulacion': simulacion ? '1' : '0' });
        return respuesta(404, { codigo: 'no_encontrado' });
    };
    return { fetch, llamadas };
}

const nuevo = (agente, extra = {}) => crearCapturaCanon({ agenteUrl: 'http://127.0.0.1:47391', fetch: agente.fetch, obtenerTicket: async (id) => ({ ticket: `t-${id}` }), ...extra });

test('agente que no responde se informa como no disponible', async () => {
    const captura = nuevo(agenteSimulado({ falla: { '/v1/estado': 'red' } }));
    assert.equal(await captura.verificar(), 'agente_no_disponible');
    await assert.rejects(captura.iniciar(7), /no responde/);
});

test('sin SDK verificado o con cámara desconectada no se permite capturar', async () => {
    assert.equal(await nuevo(agenteSimulado({ camara: 'sdk_no_instalado' })).verificar(), 'sdk_no_instalado');
    const desconectada = nuevo(agenteSimulado({ camara: 'desconectada' }));
    assert.equal(await desconectada.verificar(), 'camara_desconectada');
    await assert.rejects(desconectada.iniciar(7), /no detecta la cámara/);
});

test('servidor sin agente configurado se distingue de un agente caído', async () => {
    const sinConfigurar = Object.assign(new Error('no configurado'), { codigo: 'agente_no_configurado' });
    const captura = nuevo(agenteSimulado(), { obtenerTicket: async () => { throw sinConfigurar; } });
    await assert.rejects(captura.iniciar(7));
    assert.equal(captura.estado, 'agente_no_configurado');
});

test('flujo completo: vista previa, captura ligada al elemento y al ticket, y cierre de sesión', async () => {
    const agente = agenteSimulado();
    const captura = nuevo(agente);
    await captura.iniciar(7);
    assert.equal(captura.estado, 'vista_previa');

    const fotogramas = [];
    captura.vigilar((b) => fotogramas.push(b));
    await new Promise((r) => setTimeout(r, 20));
    assert.ok(fotogramas.length >= 1);

    const resultado = await captura.capturar();
    assert.equal(resultado.elementoId, 7);
    assert.equal(resultado.ticket, 't-7');
    assert.equal(resultado.simulacion, false);
    assert.equal(resultado.blob.type, 'image/jpeg');
    assert.equal(captura.estado, 'capturada');

    await captura.repetir();
    assert.equal(captura.estado, 'vista_previa');
    assert.equal(await captura.cancelar(), true);
    assert.ok(agente.llamadas.includes('DELETE /v1/sesiones'));
    assert.equal(captura.estado, 'lista');
    assert.equal(captura.ticket, null);
});

test('cancelar no produce fotografía y libera la sesión del agente', async () => {
    const agente = agenteSimulado();
    const captura = nuevo(agente);
    await captura.iniciar(7);
    await captura.cancelar();
    assert.equal(agente.llamadas.includes('POST /v1/captura'), false);
    await assert.rejects(captura.capturar(), /Inicie la vista previa/);
});

test('sesión abierta para otro elemento se cancela', async () => {
    const agente = agenteSimulado({ elementoSesion: 99 });
    const captura = nuevo(agente);
    await assert.rejects(captura.iniciar(7), /otro elemento/);
    assert.ok(agente.llamadas.includes('DELETE /v1/sesiones'));
    assert.equal(captura.elementoId, null);
});

test('error de comunicación o imagen no JPEG durante la captura nunca se reporta como éxito', async () => {
    const caida = nuevo(agenteSimulado({ falla: { '/v1/captura': { http: 502, cuerpo: { codigo: 'error_camara', mensaje: 'la cámara no entregó la imagen' } } } }));
    await caida.iniciar(7);
    await assert.rejects(caida.capturar(), /no entregó la imagen/);
    assert.equal(caida.estado, 'error');

    const agenteNoJpeg = agenteSimulado();
    const original = agenteNoJpeg.fetch;
    agenteNoJpeg.fetch = async (url, op) => (url.endsWith('/v1/captura') ? respuesta(200, new Uint8Array([0x89, 0x50, 0x4e, 0x47])) : original(url, op));
    const noJpeg = nuevo(agenteNoJpeg);
    await noJpeg.iniciar(7);
    await assert.rejects(noJpeg.capturar(), /JPEG válida/);
});

test('cámara desconectada durante la vista previa detiene el ciclo con el estado correcto', async () => {
    const captura = nuevo(agenteSimulado({ falla: { '/v1/fotograma': { http: 503, cuerpo: { codigo: 'desconectada', mensaje: 'cámara desconectada' } } } }));
    await captura.iniciar(7);
    captura.vigilar(() => assert.fail('no debe llegar ningún fotograma'));
    await new Promise((r) => setTimeout(r, 20));
    assert.equal(captura.estado, 'camara_desconectada');
});

test('una captura del modo simulación queda marcada como simulación', async () => {
    const captura = nuevo(agenteSimulado({ simulacion: true }));
    assert.equal(await captura.verificar(), 'lista');
    assert.match(captura.mensaje, /SIMULACIÓN/);
    await captura.iniciar(7);
    const resultado = await captura.capturar();
    assert.equal(resultado.simulacion, true);
    assert.match(captura.mensaje, /no puede guardarse/);
});
