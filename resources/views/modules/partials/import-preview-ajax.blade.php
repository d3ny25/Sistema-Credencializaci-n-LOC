<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('importPreviewManager', (config) => ({
            url: config.url,
            isLoading: false,
            errorMessage: null,
            abortController: null,

            init() {
                // Soporte para navegación con botones Atrás / Adelante del navegador
                window.addEventListener('popstate', (event) => {
                    const targetUrl = (event.state && event.state.url) ? event.state.url : window.location.href;
                    this.fetchData(targetUrl, false);
                });
            },

            submitForm() {
                const form = this.$refs.filterForm;
                if (!form) return;

                const formData = new FormData(form);
                const params = new URLSearchParams();

                for (const [key, value] of formData.entries()) {
                    const trimmed = typeof value === 'string' ? value.trim() : value;
                    if (trimmed !== '') {
                        params.append(key, trimmed);
                    }
                }

                const queryString = params.toString();
                const targetUrl = queryString ? `${this.url}?${queryString}` : this.url;
                this.fetchData(targetUrl, true);
            },

            async fetchData(fetchUrl, updateHistory = true) {
                if (this.abortController) {
                    this.abortController.abort();
                }
                this.abortController = new AbortController();

                this.isLoading = true;
                this.errorMessage = null;

                try {
                    const response = await fetch(fetchUrl, {
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                        signal: this.abortController.signal,
                    });

                    if (!response.ok) {
                        throw new Error(`Error en el servidor (${response.status}). Intente nuevamente.`);
                    }

                    const data = await response.json();

                    // Actualización atómica de chips de filtrado rápido
                    if (this.$refs.chipsContainer && data.chips_html !== undefined) {
                        this.$refs.chipsContainer.innerHTML = data.chips_html;
                    }

                    // Actualización atómica del contenedor de la tabla
                    if (this.$refs.tableContainer && data.table_html !== undefined) {
                        this.$refs.tableContainer.innerHTML = data.table_html;

                        this.$nextTick(() => {
                            if (window.Alpine && this.$refs.tableContainer) {
                                Alpine.initTree(this.$refs.tableContainer);
                            }
                        });
                    }

                    // Actualización del historial del navegador
                    if (updateHistory) {
                        window.history.pushState({ url: fetchUrl }, '', fetchUrl);
                    }

                    // Sincronizar campos del formulario con la URL actual
                    this.syncFormWithUrl(fetchUrl);

                } catch (err) {
                    if (err.name === 'AbortError') return;
                    console.error('Error al actualizar resultados de importación:', err);
                    this.errorMessage = err.message || 'No fue posible actualizar los resultados. Compruebe su conexión.';
                } finally {
                    this.isLoading = false;
                }
            },

            handleContainerClick(event) {
                // 1. Paginación: interceptar clics en enlaces .pagination a
                const paginationLink = event.target.closest('.pagination a, [rel="prev"], [rel="next"]');
                if (paginationLink && paginationLink.href) {
                    event.preventDefault();
                    this.fetchData(paginationLink.href, true);
                    return;
                }

                // 2. Chips de filtro rápido
                const chipLink = event.target.closest('[data-filter-chip]');
                if (chipLink && chipLink.href) {
                    event.preventDefault();
                    this.fetchData(chipLink.href, true);
                    return;
                }

                // 3. Limpiar filtros
                const clearLink = event.target.closest('[data-clear-filters]');
                if (clearLink && clearLink.href) {
                    event.preventDefault();
                    this.fetchData(clearLink.href, true);
                    return;
                }
            },

            syncFormWithUrl(currentUrl) {
                try {
                    const urlObj = new URL(currentUrl, window.location.origin);
                    const params = urlObj.searchParams;
                    const form = this.$refs.filterForm;
                    if (!form) return;

                    ['buscar', 'accion', 'solo_errores'].forEach(name => {
                        const input = form.elements[name];
                        if (input) {
                            if (input.type === 'checkbox') {
                                input.checked = params.get(name) === '1' || params.get(name) === 'true';
                            } else {
                                input.value = params.get(name) || '';
                            }
                        }
                    });
                } catch (e) {
                    // Ignorar errores de parsing de URL
                }
            }
        }));
    });
</script>

