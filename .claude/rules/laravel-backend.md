
---
paths:
  - "app/**/*.php"
  - "routes/**/*.php"
  - "bootstrap/**/*.php"
  - "config/**/*.php"
---

# Laravel Backend Rules

- Preferir Form Requests cuando el proyecto ya utilice ese patrón; no duplicar reglas de validación.
- Respetar middleware, policies y permisos existentes.
- Evitar consultas repetitivas y problemas N+1.
- Mantener compatibilidad con la versión instalada de Laravel.
