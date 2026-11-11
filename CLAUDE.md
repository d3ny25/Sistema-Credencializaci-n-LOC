
# CLAUDE.md — Sistema Estatal LOC 139

## 1. Contexto del proyecto

Sistema Estatal para la Gestión y Control de Credenciales de Portación de Armas de Fuego del Personal Autorizado en la Licencia Oficial Colectiva No. 139.

Aplicación desarrollada con Laravel.

Antes de realizar cambios, verifica las versiones reales de PHP, Laravel, dependencias y base de datos mediante los archivos de configuración y comandos existentes. No presupongas que la documentación está actualizada.

## 2. Principios de desarrollo

- Priorizar soluciones simples, mantenibles y funcionales.
- Reutilizar código, componentes, servicios y lógica existentes.
- No duplicar funcionalidades.
- No introducir dependencias sin justificar su necesidad.
- No crear abstracciones especulativas.
- Realizar cambios pequeños y enfocados.
- Respetar la arquitectura y convenciones existentes.
- No reconstruir módulos que ya funcionan.

## 3. Antes de modificar código

1. Investigar cómo funciona actualmente la funcionalidad.
2. Identificar rutas, controladores, modelos, servicios, vistas y pruebas involucradas.
3. Revisar implementaciones similares existentes.
4. Identificar dependencias y posibles efectos secundarios.
5. Proponer un plan cuando el cambio afecte varios componentes.

No modificar archivos únicamente porque parezcan innecesarios sin comprobar sus referencias.

## 4. Seguridad e integridad

- Respetar autenticación, autorización, roles y permisos.
- Validar datos de entrada en el servidor.
- No exponer información sensible en respuestas, registros o pruebas.
- No leer ni mostrar secretos de archivos .env.
- No utilizar información personal real en pruebas.
- No modificar datos operativos reales.
- No ejecutar operaciones destructivas sobre bases de datos.

## 5. Base de datos

- Inspeccionar las migraciones y relaciones existentes antes de modificar el esquema.
- Evitar cambios incompatibles con datos existentes.
- Respetar las restricciones y relaciones actuales.
- Utilizar transacciones cuando una operación modifique múltiples registros relacionados.
- No ejecutar migrate:fresh, db:wipe o seeders sobre bases de datos operativas.

## 6. Pruebas y verificación

- Identificar las pruebas pertinentes antes de modificar código.
- Ejecutar primero las pruebas específicas del módulo afectado.
- Ejecutar pruebas adicionales cuando el alcance lo justifique.
- No afirmar que algo funciona si no se ha verificado.
- Informar claramente las pruebas ejecutadas y sus resultados.

## 7. Gestión de cambios

- Revisar git status antes de modificar archivos.
- No sobrescribir cambios existentes del usuario.
- No realizar commits, push, reset o eliminación de archivos sin autorización.
- Presentar un resumen de archivos modificados y motivos.

## 8. Respuestas

Utilizar respuestas concretas y técnicas.

Evitar explicaciones extensas cuando una respuesta breve sea suficiente.

Distinguir entre:
- Hechos comprobados.
- Hipótesis.
- Recomendaciones.
- Problemas pendientes.

No mostrar archivos completos si basta con presentar el fragmento relevante.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- When graphify-out/graph.json exists, run `graphify query "<question>"` before your first grep, search or source-file read for a codebase question, and put this rule in every subagent prompt that explores code. Read raw files only for lines the graph or an earlier read already located. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
- A committed graphify-out/graph.json is rewritten whole on every build, so a large diff to it in a pull request is expected. Do not flag it as churn or suggest skipping the graph rebuild or its hooks.
