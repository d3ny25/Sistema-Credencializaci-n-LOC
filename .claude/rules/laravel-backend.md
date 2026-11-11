
---
paths:
  - "app/**/*.php"
  - "routes/**/*.php"
  - "bootstrap/**/*.php"
  - "config/**/*.php"
---

# Laravel Backend Rules

- Seguir las convenciones existentes del proyecto.
- Revisar primero controladores, servicios y modelos relacionados.
- Preferir Form Requests cuando el proyecto ya utilice ese patrón.
- No duplicar reglas de validación.
- Respetar middleware, policies y permisos.
- Evitar consultas repetitivas y problemas N+1.
- Utilizar transacciones cuando se necesite mantener la integridad de operaciones relacionadas.
- No agregar capas o servicios innecesarios.
- Mantener compatibilidad con la versión instalada de Laravel.
