import './bootstrap';
import './credential-template-editor';
import Alpine from 'alpinejs';

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
