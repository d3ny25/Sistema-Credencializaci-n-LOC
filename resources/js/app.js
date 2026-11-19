import './bootstrap';
import './credential-template-editor';
import { crearCapturaTopaz } from './topaz-signature';
import { crearCapturaCanon, crearCapturaHuella } from './canon-agente';
import { crearSeguimientoImpresion } from './impresion-agente';
import { crearNavegacionEntrega } from './entrega-navegacion';
import Alpine from 'alpinejs';

window.crearCapturaTopaz = crearCapturaTopaz;
window.crearCapturaCanon = crearCapturaCanon;
window.crearCapturaHuella = crearCapturaHuella;
window.crearSeguimientoImpresion = crearSeguimientoImpresion;
window.crearNavegacionEntrega = crearNavegacionEntrega;
window.Alpine = Alpine;
Alpine.start();

const sidebar = document.querySelector('#sidebar');
const sidebarToggle = document.querySelector('#sidebar-toggle');
const sidebarOverlay = document.querySelector('#sidebar-overlay');

function setSidebarOpen(isOpen) {
    sidebar?.classList.toggle('-translate-x-full', !isOpen);
    sidebarOverlay?.classList.toggle('hidden', !isOpen);
    sidebarToggle?.setAttribute('aria-expanded', String(isOpen));
}

sidebarToggle?.addEventListener('click', () => {
    setSidebarOpen(sidebarToggle.getAttribute('aria-expanded') !== 'true');
});

sidebarOverlay?.addEventListener('click', () => setSidebarOpen(false));
