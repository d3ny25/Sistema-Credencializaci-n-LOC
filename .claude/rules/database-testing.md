
---
paths:
  - "database/**/*.php"
  - "tests/**/*.php"
---

# Database and Testing Rules

## Database

- Revisar las migraciones existentes antes de crear otras; respetar claves foráneas, índices y restricciones.
- Evitar pérdida de información durante modificaciones de esquema.

## Testing

- Identificar la configuración de pruebas (`phpunit.xml`) antes de ejecutar comandos; utilizar la base de datos de pruebas, nunca una base operativa.
- Comprobar casos exitosos, de error y de permisos.
- No modificar el código productivo para hacer pasar artificialmente una prueba.
