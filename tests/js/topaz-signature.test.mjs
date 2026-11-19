// Pruebas del adaptador SigWeb con un SDK simulado: no prueban el pad físico ni el servicio real.
// Ejecutar: node --test tests/js/topaz-signature.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { crearCapturaTopaz } from '../../resources/js/topaz-signature.js';

const PNG_B64 = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 1, 2, 3]).toString('base64');

function sdkSimulado({ version = '1.7.3.0', conectado = 1, puntos = 25, imagen = PNG_B64, sinImagen = false } = {}) {
    const llamadas = [];
    const registrar = (nombre, valor) => (...args) => { llamadas.push([nombre, ...args]); return typeof valor === 'function' ? valor(...args) : valor; };
    return {
        llamadas,
        estadoPad: () => llamadas.filter(([n]) => n === 'SetTabletState').map(([, v]) => v),
        GetSigWebVersion: registrar('GetSigWebVersion', `"${version}"`),
        TabletConnectQuery: registrar('TabletConnectQuery', conectado),
        SetDisplayXSize: registrar('SetDisplayXSize'),
        SetDisplayYSize: registrar('SetDisplayYSize'),
        SetJustifyMode: registrar('SetJustifyMode'),
        ClearTablet: registrar('ClearTablet'),
        SetTabletState: registrar('SetTabletState', (estado) => (estado === 1 ? 42 : null)),
        NumberOfTabletPoints: registrar('NumberOfTabletPoints', () => puntos),
        SetImageXSize: registrar('SetImageXSize'),
        SetImageYSize: registrar('SetImageYSize'),
        SetImagePenWidth: registrar('SetImagePenWidth'),
        GetSigImageB64: registrar('GetSigImageB64', (cb) => { if (!sinImagen) cb(imagen); }),
    };
}

const lienzo = () => ({ width: 500, height: 100, getContext: () => ({ clearRect() {} }) });

test('sin SDK cargado informa sdk_ausente y no permite capturar', () => {
    const captura = crearCapturaTopaz({ sdk: {} });
    assert.equal(captura.verificar(), 'sdk_ausente');
    assert.throws(() => captura.iniciar(7, lienzo()), /SigWebTablet\.js no está instalado/);
});

test('un SDK sin las funciones requeridas es incompatible y nombra lo que falta', () => {
    const { GetSigImageB64, ...incompleto } = sdkSimulado();
    const captura = crearCapturaTopaz({ sdk: incompleto });
    assert.equal(captura.verificar(), 'sdk_incompatible');
    assert.match(captura.mensaje, /GetSigImageB64/);
});

test('servicio SigWeb caído o sin versión válida no se confunde con disponible', () => {
    const caido = sdkSimulado();
    caido.GetSigWebVersion = () => { throw new Error('net::ERR_CONNECTION_REFUSED'); };
    assert.equal(crearCapturaTopaz({ sdk: caido }).verificar(), 'servicio_no_disponible');

    const sinVersion = sdkSimulado({ version: '' });
    assert.equal(crearCapturaTopaz({ sdk: sinVersion }).verificar(), 'servicio_no_disponible');
});

test('servicio activo sin pad conectado se distingue de pad listo', () => {
    assert.equal(crearCapturaTopaz({ sdk: sdkSimulado({ conectado: 0 }) }).verificar(), 'pad_no_conectado');
    const captura = crearCapturaTopaz({ sdk: sdkSimulado() });
    assert.equal(captura.verificar(), 'listo');
    assert.equal(captura.version, '1.7.3.0');
});

test('iniciar activa el pad con el lienzo y queda ligado al elemento', () => {
    const sdk = sdkSimulado();
    const captura = crearCapturaTopaz({ sdk });
    captura.iniciar(7, lienzo());
    assert.equal(captura.estado, 'capturando');
    assert.equal(captura.elementoId, 7);
    assert.deepEqual(sdk.estadoPad(), [1]);
    assert.throws(() => captura.iniciar(8, lienzo()), /captura en curso/);
});

test('aceptar sin trazo no termina la captura', async () => {
    const sdk = sdkSimulado({ puntos: 0 });
    const captura = crearCapturaTopaz({ sdk });
    captura.iniciar(7, lienzo());
    await assert.rejects(captura.aceptar(), /no registró ningún trazo/);
    assert.equal(captura.estado, 'capturando');
});

test('aceptar devuelve un PNG del elemento de la captura y libera el pad', async () => {
    const sdk = sdkSimulado();
    const captura = crearCapturaTopaz({ sdk });
    captura.iniciar(7, lienzo());
    const resultado = await captura.aceptar();
    assert.equal(resultado.elementoId, 7);
    assert.equal(resultado.puntos, 25);
    assert.equal(resultado.blob.type, 'image/png');
    assert.deepEqual(sdk.estadoPad(), [1, 0]);
    assert.equal(captura.estado, 'listo');
    assert.equal(captura.elementoId, null);
});

test('una imagen que no es PNG o que no llega se reporta como error, no como éxito', async () => {
    const noPng = crearCapturaTopaz({ sdk: sdkSimulado({ imagen: Buffer.from('hola').toString('base64') }) });
    noPng.iniciar(7, lienzo());
    await assert.rejects(noPng.aceptar(), /no devolvió una imagen PNG/);
    assert.equal(noPng.estado, 'error');

    const sinRespuesta = crearCapturaTopaz({ sdk: sdkSimulado({ sinImagen: true }), tiempoImagenMs: 20 });
    sinRespuesta.iniciar(7, lienzo());
    await assert.rejects(sinRespuesta.aceptar(), /a tiempo/);
});

test('cancelar libera el pad sin producir firma', () => {
    const sdk = sdkSimulado();
    const captura = crearCapturaTopaz({ sdk });
    captura.iniciar(7, lienzo());
    assert.equal(captura.cancelar(), true);
    assert.deepEqual(sdk.estadoPad(), [1, 0]);
    assert.equal(captura.estado, 'listo');
    assert.equal(captura.elementoId, null);
    assert.equal(sdk.llamadas.some(([n]) => n === 'GetSigImageB64'), false);
});

test('cambiar de elemento durante la captura la cancela y permite iniciar otra para el nuevo elemento', () => {
    const sdk = sdkSimulado();
    const captura = crearCapturaTopaz({ sdk });
    captura.iniciar(7, lienzo());
    captura.cancelar();
    captura.iniciar(8, lienzo());
    assert.equal(captura.elementoId, 8);
});
