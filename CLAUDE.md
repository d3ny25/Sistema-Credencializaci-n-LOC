# Sistema Estatal LOC 139 — Instrucciones del proyecto

Complementa las instrucciones globales (`~/.claude/CLAUDE.md`); aquí solo lo específico del proyecto.

## 1. Contexto y stack

- Aplicación Laravel para gestión y control de credenciales del personal autorizado de la Licencia Oficial Colectiva No. 139. Respeta el propósito institucional y los procesos de negocio existentes.
- Stack verificado (2026-11): PHP 8.4, Laravel 13.x, PHPUnit 12 (no Pest), Laravel Pint, MySQL en local; las pruebas usan sqlite en memoria según `phpunit.xml`. Ante cambios mayores, reconfirma versiones en `composer.json` y la configuración.
- Existe una sub-aplicación anidada en `loc-importador/` con sus propios modelos y servicios; no la confundas con la aplicación principal.
- No supongas cómo funciona un proceso por el nombre de una clase, tabla o pantalla: verifica la implementación. El código vigente prevalece sobre la documentación.

## 2. Investigación previa

Antes de modificar un comportamiento:

1. Identifica rutas, controladores, modelos, servicios, vistas y pruebas involucrados, y sigue el flujo desde la entrada hasta la persistencia o respuesta.
2. Revisa implementaciones similares para reutilizar los patrones del proyecto.
3. Comprueba relaciones, permisos y efectos secundarios, y las pruebas que protegen el comportamiento.
4. Si el cambio afecta varias áreas, define primero un plan breve con los archivos probables.

## 3. Seguridad y datos sensibles

- Respeta autenticación, autorización, roles y permisos existentes; valida siempre en el servidor, no solo en la interfaz.
- No expongas información sensible en vistas, respuestas, errores, logs, reportes ni pruebas.
- No muestres ni copies valores de `.env`; comprueba la existencia de variables sin revelar su valor.
- No utilices datos personales reales en pruebas, seeders, ejemplos ni demostraciones. Los archivos bajo `Gestion de informacion/` y `evidencias/` contienen o contuvieron datos reales: no los leas ni los repliques salvo instrucción expresa.
- No consultes ni modifiques datos operativos reales sin autorización expresa y procedimiento seguro.
- No debilites controles de seguridad para facilitar una prueba o resolver un error.

## 4. Base de datos y migraciones

- Inspecciona migraciones, relaciones, claves e índices antes de cambiar el esquema; comprueba compatibilidad con MySQL y el impacto sobre datos y módulos que consumen las tablas.
- Usa transacciones cuando varias escrituras deban mantenerse consistentes.
- No ejecutes `migrate:fresh`, `db:wipe`, seeders que alteren datos operativos, ni migraciones en entornos compartidos sin autorización explícita.
- Antes de proponer una migración, explica su impacto y requisitos de respaldo o despliegue.

## 5. Pruebas y verificación

- Ejecuta primero las pruebas específicas del área afectada (`php artisan test --filter=...`); amplía a la suite cuando el alcance o riesgo lo justifique.
- Para cambios de interfaz valida interacciones y estados relevantes; usa Playwright cuando sea necesario.
- Si no existen pruebas adecuadas, indícalo y ejecuta otras verificaciones disponibles.
- No modifiques pruebas para hacer pasar una implementación incorrecta.

## 6. Git — protección específica

- Además de las reglas globales: este repositorio suele tener trabajo local sin confirmar, incluidas eliminaciones preparadas en staging. Respétalo, no lo descartes ni lo "limpies", y no elimines archivos sin autorización explícita.

## 7. Uso condicional de Graphify

Graphify es una ayuda de navegación, no un requisito. No lo instales ni detengas una tarea por su ausencia.

- Los hooks del proyecto ya sugieren el grafo automáticamente; síguelos cuando aparezcan.
- Si el comando `graphify` y `graphify-out/graph.json` están disponibles, para preguntas de arquitectura o relaciones usa primero `graphify query "<pregunta>"`, `graphify path "A" "B"` o `graphify explain "X"` antes de búsquedas indiscriminadas o lecturas extensas. Contrasta siempre sus resultados con el código real: el grafo puede estar desactualizado.
- Si existe `graphify-out/wiki/`, puedes usarla para navegación amplia; actualmente no está generada. Consulta `graphify-out/GRAPH_REPORT.md` solo para revisiones arquitectónicas amplias.
- No ejecutes `graphify update .` por iniciativa propia: reescribe archivos trackeados bajo `graphify-out/`. Si una modificación requiere actualizar el grafo, solicita autorización antes; nunca mezcles esos archivos en commits funcionales sin avisar.
- No leas `graphify-out/graph.json` directamente (3+ MB); usa los subcomandos.
- Si Graphify falla, informa el problema y continúa con exploración convencional. Los subagentes que exploren código deben respetar esta misma política.

## 8. Delegación y archivos a evitar

- Exploración amplia: `graphify query` primero; si no basta, un solo explorador (Explore nativo o `codebase-explorer`). Revisión: un solo revisor por tarea (`code-reviewer` del proyecto). Verificación de pruebas: `test-verifier`.
- No leas ni proceses volcados masivos generados salvo petición expresa: salidas de Repomix (`repomix-output.xml` en este directorio o en el padre), `../Sistema2.xml` u otros exports similares.

## 9. Alcance y comunicación

- Cambios que afecten varios componentes, permisos, datos o procesos institucionales: explica brevemente el enfoque antes de implementar.
- Si el requerimiento contradice el comportamiento actual y puede afectar datos, permisos o procesos, detente y explica la diferencia.
- Ante conflicto entre instrucciones, prevalecen las restricciones de seguridad y protección de datos; pide aclaración si el conflicto afecta el alcance.
