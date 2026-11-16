# Backend API

Laravel 13 con PostgreSQL configurado, independiente del frontend. No necesita Node ni un build de Vite.

Desde esta carpeta:

```powershell
composer install --no-interaction --prefer-dist
composer check-platform-reqs
composer run setup
composer run dev
```

Usar setup solo en un entorno nuevo: crea .env si falta y genera APP_KEY. No ejecuta migraciones ni seeds.

GET http://127.0.0.1:8000/api/v1/health devuelve HTTP 200 y `{"status":"ok"}`. No comprueba PostgreSQL. Para usar registro, acceso y roles en el arranque nativo, configura una base PostgreSQL propia en `.env` y ejecuta `php artisan migrate`. El PostgreSQL interno de Docker no publica su puerto. No hay seeds ni factories de usuarios ficticios.

Comprueba el proyecto con `composer validate --strict`, `composer audit --locked` y `php artisan route:list --path=api/v1`.

El contrato de salud y las respuestas de error se documentan en [OpenAPI](openapi.json) y en la [guía de la API](../docs/API_GUIDE.md). El inventario de rutas se puede consultar con `php artisan route:list --path=api --json`.

Usa `composer run format:check` para comprobar estilo, `composer run format` para aplicarlo y `composer test` para ejecutar PHPUnit. Las 32 pruebas actuales cubren salud, errores, autenticación, CSRF, roles y validación. El bootstrap crea una clave por proceso; SQLite en memoria es el valor predeterminado. El [script de pruebas](../scripts/Test-Backend.ps1) ejecuta también PostgreSQL en un proyecto aislado. No apuntar RefreshDatabase a desarrollo.

Para el flujo completo verificado, usa el [README principal](../README.md) y Docker. La herramienta Composer reconstruida del contenedor tiene su grafo auditado; comprueba por separado cualquier instalación nativa. Consulta el [seguridad del entorno](../README.md#seguridad-del-entorno). La [guía de autenticación](../docs/AUTHENTICATION.md) detalla cookies, roles y validación. Las rutas automáticas de archivos locales están desactivadas hasta que exista una necesidad funcional.
