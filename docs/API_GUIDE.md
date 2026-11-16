# Convenciones de la API

El contrato vigente es [OpenAPI 3.1.1](../backend/openapi.json). Desde la raíz del repositorio, con Docker Compose iniciado, se puede consultar la API desde PowerShell.

```powershell
Invoke-RestMethod http://localhost:8080/api/v1/health
Invoke-WebRequest http://localhost:8080/api/v1/health -Method Head
```

`GET /api/v1/health` devuelve HTTP 200 y `{"status":"ok"}`. `HEAD` devuelve el estado y las cabeceras sin cuerpo. Son operaciones públicas, sin parámetros, y comprueban que Laravel responde por HTTP. PostgreSQL se comprueba por separado. La [guía de autenticación](AUTHENTICATION.md) explica las operaciones de registro, sesión e identidad.

## Rutas y recursos

### Swagger UI local

La interfaz utiliza un tema oscuro propio por defecto, coherente con la paleta de la aplicación.

Con desarrollo arrancado, abre `/api-docs/` en el origen de la aplicación: por ejemplo, [Swagger UI](http://localhost:8080/api-docs/). El puerto depende de `APP_PORT` del `.env` raíz. Despliega `GET /api/v1/health`, pulsa **Try it out** y **Execute**; debe devolver 200 y `{"status":"ok"}`.

La interfaz lee el contrato vigente desde `/api-docs/openapi.json`, servido del archivo `backend/openapi.json` montado en modo lectura. Adapta el servidor al origen actual del navegador, sin cambiar los servidores documentados en el archivo. No se cargan recursos de CDN ni se envía el contrato a un validador externo. Solo está disponible en el servidor de desarrollo de Vite; no forma parte del build de producción.

La autenticación usa sesión y cookies, no Bearer tokens. Puedes iniciar sesión en `/account` en el mismo navegador y origen y consultar `GET /api/v1/user` desde Swagger. También puedes ejecutar el login documentado con **Try it out**: antes de mutaciones, el cliente obtiene CSRF y añade `X-XSRF-TOKEN`. No copies la cookie HttpOnly al diálogo **Authorize**; la gestiona el navegador. Las pruebas de CSRF del cliente están automatizadas; no se afirma un recorrido completo de registro/login real mediante Swagger. Un logout desde Swagger también cierra esa sesión en la aplicación.

Las tablas no crean endpoints automáticamente: `courses` todavía no dispone de operaciones HTTP implementadas/documentadas. Añádelas al contrato cuando se desarrollen.

- Registrar las rutas en `backend/routes/api.php` dentro del grupo `/api/v1`. Una ruptura de contrato requiere una versión nueva.
- Usar nombres de recurso en plural y segmentos en `kebab-case`. Usar identificadores enteros cuando correspondan al modelo. Anidar una relación cuando el padre determine el alcance y comprobar esa relación en el servidor.
- Usar `GET` para lectura, `POST` para crear, `PUT` para reemplazar, `PATCH` para cambios parciales y `DELETE` para eliminar. Responder con `200` para lecturas y escrituras con representación, `201` para creaciones y `204` cuando corresponda devolver una respuesta vacía. Para una creación con URI de consulta, enviar `Location`.
- Usar Laravel API Resources para serializar las entidades cuando existan. Las colecciones paginadas usarán `data`, `links` y `meta` del paginador de Laravel.

## Colecciones futuras

Catálogo administrativo de especialidades: GET/HEAD `/api/v1/specialties` paginado y POST `/api/v1/specialties` para crear un nombre único. Solo Administrator; la creación exige cookies/CSRF. Crear la especialidad no la asigna a nadie; la asociación se hace editando un usuario de rol Coach. Un estudiante no puede recibir especialidades mientras conserve ese rol. [Contrato OpenAPI](../backend/openapi.json).

Primera colección implementada: `GET /api/v1/users`, exclusiva de Administrator, con paginación, filtro por rol y ordenación. `PATCH /api/v1/users/{user}` permite edición administrativa parcial con cookies y CSRF. [Contrato OpenAPI](../backend/openapi.json). Ambas operaciones están disponibles en Swagger.

Cada operación de listado declarará en OpenAPI los parámetros que admite. Como convención inicial, `page` empieza en 1 y `per_page` tiene un valor predeterminado de 20 y un máximo de 100. Los filtros usan `filter[campo]` y la ordenación usa `sort=campo` o `sort=-campo`. Validar nombres, valores y límites con una lista permitida por recurso. Ante campos de orden iguales, usar el identificador como desempate para obtener páginas deterministas. Los parámetros inválidos de una operación que los admita devuelven `422`.

## Contrato de errores

Las excepciones gestionadas por Laravel bajo `/api` devuelven JSON aunque la petición omita `Accept: application/json`. `error` es un mensaje legible y `code` coincide con el estado HTTP. Los clientes deben decidir según el estado o `code`. El manejador conserva cabeceras de protocolo como `Allow`, `Retry-After` y `WWW-Authenticate` cuando Laravel las emite. Los diagnósticos internos se gestionan en los logs. HEAD conserva el estado y omite el cuerpo.

```json
{ "error": "Not Found", "code": 404 }
```

Para validaciones de Laravel se añade `errors`, un objeto con listas de mensajes por campo. Los mensajes de validación deben evitar reproducir datos sensibles. Los rechazos generados directamente por Nginx, como un cuerpo que exceda su límite, siguen el formato de Nginx.

| Estado | Uso |
| --- | --- |
| 400 | Petición con formato inválido que la operación compruebe expresamente |
| 401 | Autenticación requerida |
| 403 | Identidad conocida con permiso insuficiente |
| 404 | Ruta o recurso inexistente |
| 405 | Método no admitido. Consultar la cabecera `Allow` |
| 422 | Validación de campos fallida |
| 500 | Fallo interno inesperado |

La versión actual permite comprobar `404` con una ruta API desconocida y `405` con un método no admitido en salud. Las pruebas simulan `500` en el contenedor de Laravel. Las operaciones de autenticación añaden casos de `401`, `419`, `422` y `429`.

## Incorporar un recurso

1. Definir URI, método, estados y nombres de ruta bajo el grupo versionado.
2. Crear un controlador ligero que coordine entrada y salida. Usar un Form Request si hay datos que validar. Usar un servicio cuando exista una regla de negocio que merezca una responsabilidad propia. Aplicar middleware para acceso general y una Policy cuando haya permisos sobre un recurso.
3. Para persistencia, definir el modelo Eloquent y su migración. Ejecutar migraciones explícitamente y probar el esquema sobre una base PostgreSQL aislada cuando el recurso lo requiera.
4. Añadir pruebas Feature de casos correctos y errores pertinentes. Incluir validación, permisos y efectos persistentes según el recurso. Ejecutar `composer test` y `composer run format:check`.
5. Actualizar `backend/openapi.json` en el mismo cambio con las operaciones y respuestas reales. Comparar con `php artisan route:list --path=api --json` y comprobar los ejemplos contra la API. Para pruebas desde Docker, anteponer `docker compose exec -T backend` a los comandos PHP o Composer.

Referencias de implementación. [OpenAPI 3.1.1](https://spec.openapis.org/oas/v3.1.1.html) · [Excepciones en Laravel](https://laravel.com/docs/13.x/errors) · [Recursos de Eloquent](https://laravel.com/docs/13.x/eloquent-resources)
