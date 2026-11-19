// Pruebas del seguimiento de impresión con un agente SIMULADO: no consultan ninguna cola ni impresora reales.
// Ejecutar: node --test tests/js/impresion-agente.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { crearSeguimientoImpresion } from '../../resources/js/impresion-agente.js';

const respuesta = (estado, cuerpo) => ({ ok: estado >= 200 && estado < 300, status: estado, json: async () => cuerpo });

function agente({ cola = { estado: 'lista' }, trabajos = [], documento = 'LOC139-abc', caido = false } = {}) {
    const llamadas = [];
    let consulta = 0;
    const fetch = async (url, { method = 'GET', headers = {} } = {}) => {
        const ruta = new URL(url).pathname;
        llamadas.push(`${method} ${ruta}`);
        if (caido) throw new TypeError('Failed to fetch');
        if (ruta === '/v1/impresion/estado') return respuesta(200, { cola: 'Zebra ZXP Series 7 USB Card Printer', dispositivo_presente: true, alertas: [], ...cola });
        if (ruta === '/v1/impresion/sesiones' && method === 'POST') return respuesta(201, { sesion: 'i1', documento });
        if (ruta === '/v1/impresion/sesiones' && method === 'DELETE') return respuesta(200, { cerrada: true });
        assert.equal(headers['X-Sesion'], 'i1');
        const t = trabajos[Math.min(consulta++, trabajos.length - 1)];
        return respuesta(200, { cola: 'Zebra ZXP Series 7 USB Card Printer', alertas: [], ...t });
    };
    return { fetch, llamadas };
}

const nuevo = (a) => crearSeguimientoImpresion({ agenteUrl: 'http://127.0.0.1:47391', fetch: a.fetch, intervaloMs: 1, esperaAparicionMs: 30, esperaMaximaMs: 200 });
const recoger = (seg) => new Promise((resolve) => { const vistos = []; seg.seguir((c) => { vistos.push(c); if (c.final) resolve(vistos); }); });

test('agente caído no impide imprimir pero se informa sin seguimiento', async () => {
    const estado = await nuevo(agente({ caido: true })).verificar();
    assert.equal(estado.estado, 'agente_no_disponible');
    assert.match(estado.mensaje, /Sin seguimiento/);
});

test('estados de la impresora según Windows se distinguen', async () => {
    for (const e of ['lista', 'cola_no_encontrada', 'impresora_desconectada', 'fuera_de_linea', 'error', 'requiere_atencion']) {
        assert.equal((await nuevo(agente({ cola: { estado: e } })).verificar()).estado, e);
    }
    assert.equal((await nuevo(agente({ cola: { estado: 'inventado' } })).verificar()).estado, 'error');
});

test('la sesión exige que el documento sea el del trabajo autorizado por Laravel', async () => {
    const a = agente({ documento: 'LOC139-otro' });
    await assert.rejects(nuevo(a).abrir('ticket', 'abc'), /identificador de trabajo distinto/);
    assert.ok(a.llamadas.includes('DELETE /v1/impresion/sesiones'));
    assert.equal(await nuevo(agente()).abrir('ticket', 'abc'), 'LOC139-abc');
});

test('el ciclo informa cada cambio y termina cuando el trabajo sale de la cola', async () => {
    const seg = nuevo(agente({ trabajos: [{ estado: 'en_cola', job_id: 4 }, { estado: 'en_cola', job_id: 4 }, { estado: 'imprimiendo', job_id: 4 }, { estado: 'salio_de_cola', job_id: 4 }] }));
    await seg.abrir('ticket', 'abc');
    const vistos = await recoger(seg);
    assert.deepEqual(vistos.map((v) => v.estado), ['en_cola', 'imprimiendo', 'salio_de_cola']);
    assert.equal(vistos.at(-1).final, true);
    assert.match(vistos.at(-1).mensaje, /salió de la cola/);
});

test('un trabajo que nunca aparece termina como no encontrado, y uno en otra cola se distingue', async () => {
    const nunca = nuevo(agente({ trabajos: [{ estado: 'no_encontrado' }] }));
    await nunca.abrir('ticket', 'abc');
    const vistos = await recoger(nunca);
    assert.equal(vistos.at(-1).estado, 'no_encontrado');
    assert.equal(vistos.at(-1).final, true);

    const otra = nuevo(agente({ trabajos: [{ estado: 'en_otra_cola', cola: 'Microsoft Print to PDF', job_id: 2 }] }));
    await otra.abrir('ticket', 'abc');
    const [unico] = await recoger(otra);
    assert.equal(unico.estado, 'en_otra_cola');
    assert.equal(unico.cola, 'Microsoft Print to PDF');
});

test('un error de la cola se informa sin darlo por terminado hasta el tiempo máximo', async () => {
    const seg = nuevo(agente({ trabajos: [{ estado: 'en_cola', job_id: 9 }, { estado: 'error', job_id: 9, alertas: ['sin_tarjetas'] }] }));
    await seg.abrir('ticket', 'abc');
    const vistos = await recoger(seg);
    const error = vistos.find((v) => v.estado === 'error');
    assert.deepEqual(error.alertas, ['sin_tarjetas']);
    assert.equal(vistos.at(-1).final, true);
});

test('perder el agente durante el seguimiento termina como sin seguimiento', async () => {
    const a = agente({ trabajos: [{ estado: 'en_cola', job_id: 1 }] });
    const seg = nuevo(a);
    await seg.abrir('ticket', 'abc');
    a.fetch = async () => { throw new TypeError('Failed to fetch'); };
    const seg2 = crearSeguimientoImpresion({ agenteUrl: 'http://127.0.0.1:47391', fetch: (...x) => a.fetch(...x), intervaloMs: 1 });
    seg2.sesion = 'i1';
    const vistos = await recoger(seg2);
    assert.equal(vistos.at(-1).estado, 'sin_seguimiento');
});
