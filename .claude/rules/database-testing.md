
---
paths:
  - "database/**/*.php"
  - "tests/**/*.php"
---

# Database and Testing Rules

## Database

- Revisar las migraciones existentes antes de crear otras.
- Respetar claves foráneas, índices y restricciones.
- Evitar pérdida de información durante modificaciones.
- No ejecutar migraciones destructivas.
- No utilizar datos personales reales en seeders o fixtures.

## Testing

- Identificar la configuración de pruebas antes de ejecutar comandos.
- Utilizar la base de datos de pruebas, nunca una base operativa.
- Preferir pruebas específicas antes que ejecutar toda la suite.
- Comprobar casos exitosos, errores y permisos.
- No modificar el código productivo para hacer pasar artificialmente una prueba.
