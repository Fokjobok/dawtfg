# Autenticación SPA y roles

Sanctum 4.4.0 usa el guard de sesión `web` y cookies. React y Laravel se comunican a través del mismo origen de Nginx. El registro público crea siempre Student. Las contraseñas se guardan mediante el cast de hashing de Laravel. El registro exige al menos 12 caracteres; tanto registro como login rechazan bytes nulos y más de 72 bytes, el límite de bcrypt. Se responde 422 con error de campo, sin truncar entradas en el servidor. [Contrato OpenAPI](../backend/openapi.json)

En el entorno de desarrollo, abre [Mi cuenta](http://localhost:8080/account). La pantalla permite registrarse, iniciar sesión, consultar nombre, correo y rol, comprobar la sesión y cerrarla. Las credenciales enviadas por el formulario se usan para autenticar la petición. El identificador de sesión permanece en la cookie HttpOnly.

## Flujo HTTP

1. Solicitar `GET /sanctum/csrf-cookie` con credenciales del mismo origen. Devuelve 204 y las cookies de sesión y `XSRF-TOKEN`.
2. Enviar `POST /api/v1/register` o `POST /api/v1/login` con JSON y el valor URL-decodificado de `XSRF-TOKEN` en `X-XSRF-TOKEN`. Mantener las cookies y enviar `Accept: application/json`.
3. Solicitar `GET /api/v1/user` con las cookies. Sanctum reconoce como stateful los orígenes configurados y necesita `Origin` o `Referer` para cargar la sesión en las rutas API.
4. Enviar `POST /api/v1/logout` con cookies y CSRF. Devuelve 204, invalida la sesión y regenera su token CSRF.

El login y el registro regeneran el identificador de sesión. Laravel 13 acepta también la señal `Sec-Fetch-Site: same-origin` del navegador como protección frente a falsificación de peticiones. Las pruebas HTTP comprueban la alternativa de token, con 419 cuando falta o es incorrecto. La ruta de CSRF usa el grupo web de Sanctum. Las mutaciones de autenticación tienen siempre el grupo web, incluidas las peticiones que carecen de `Origin` o `Referer`. [Documentación de Sanctum](https://laravel.com/docs/13.x/sanctum) · [Protección de peticiones en Laravel](https://laravel.com/docs/13.x/csrf)

## Sesión y respuestas

GET/HEAD `/api/v1/session` permite consultar opcionalmente la sesión al entrar en la SPA: devuelve 200 con `{"data":null}` para un visitante o `data` con la identidad propia cuando existe sesión web válida. Solo expone id, nombre, correo y rol del usuario de la cookie; no permite elegir otra cuenta ni autenticar mediante Bearer. Usa el grupo web para resolver la sesión incluso sin Origin y siempre envía `Cache-Control: no-store, private`. HEAD no tiene cuerpo. Esto permite consultar si se ha iniciado sesión sin provocar un 401 esperado en la consola del navegador.

GET `/api/v1/user` sigue siendo una consulta protegida y devuelve 401 sin sesión. Las demás rutas privadas conservan 401/403 y sus Policies. En React, `getSession()` consulta `/session`; `RequireRole` comprueba sesión y rol antes de montar administración o creación de cursos. Administración requiere Administrator y la creación admite Coach o Administrator. El catálogo de cursos es público. Estas comprobaciones de interfaz no sustituyen la autorización del backend.

La cookie del proyecto principal de desarrollo se llama `dawtfg_session`, es HttpOnly, host-only y SameSite=Lax. Compose deriva su nombre del proyecto para separar instancias paralelas sobre localhost; las cookies no se aíslan por puerto. La duración predeterminada es de 120 minutos y el almacenamiento de archivos de sesión se cifra. `Secure` se activa por defecto con `APP_ENV=production`; la configuración de producción usa `dawtfg_production_session`. Las representaciones de identidad tienen `Cache-Control: no-store, private`.

| Respuesta | Comportamiento |
| --- | --- |
| 201 | Registro correcto, identidad bajo `data` y `Location: /api/v1/user` |
| 200 | Login o consulta de identidad correctos |
| 204 | Cookies CSRF inicializadas o logout correcto |
| 401 | Sesión ausente o inválida en una ruta protegida |
| 403 | Una Policy rechaza la operación |
| 419 | Protección CSRF rechazada. Inicializar CSRF y volver a intentar la acción |
| 422 | Campos inválidos o credenciales incorrectas. Consultar `errors` |
| 429 | Se superó el límite de 5 peticiones por minuto y dirección IP en login o registro. Consultar `Retry-After` |

El frontend vuelve al formulario de login cuando la sesión deja de ser válida. El backend desactiva la búsqueda de tokens Bearer para este flujo de SPA.

## Autorización

`auth:sanctum` protege la consulta de identidad y el logout. `UserPolicy` permite consultar la identidad propia y permite a Administrator consultar un usuario ajeno cuando se le somete ese usuario a la Policy. El endpoint actual consulta exclusivamente la identidad propia. Las pruebas comprueban la decisión sobre otro usuario para Administrator, Coach y Student, además del formato HTTP de una denegación. Los permisos de futuros recursos deberán definirse en sus Policies.

La asignación de roles privilegiados requiere acceso a la consola del servidor y una cuenta previamente registrada. Desde la raíz, el operador puede ejecutar lo siguiente con el correo real de esa cuenta.

Un Administrator autenticado también puede editar roles de otros usuarios mediante `PATCH /api/v1/users/{user}`, con autorización backend y CSRF. La primera cuenta Administrator sigue requiriendo asignación de confianza desde consola. El registro público no concede roles privilegiados. Campos y respuestas en el [contrato OpenAPI](../backend/openapi.json).

```powershell
docker compose exec -T backend php artisan users:set-role cuenta@example.com Coach
```

Los roles admitidos son `Administrator`, `Coach` y `Student`. El campo `role` queda fuera de la asignación masiva y se prohíbe en el registro público.

## Pruebas aisladas

La suite predeterminada usa SQLite en memoria. El bootstrap de PHPUnit genera una clave de cifrado independiente por proceso. Para PostgreSQL, el bootstrap exige `TEST_DB_CONNECTION=pgsql` y un nombre explícito terminado en `_test`.

```powershell
docker compose build backend postgres
./scripts/Test-Backend.ps1
```

El script comprueba cada código de salida y fija el proyecto `dawtfg-auth-tests`. Sus servicios tienen nombres distintos de los de desarrollo. PostgreSQL usa `tmpfs`, credenciales públicas de prueba y un esquema independiente. PHPUnit usa `RefreshDatabase`, por lo que reconstruye la base de pruebas. Por defecto el script detiene el proyecto al terminar, incluso si falla; `-KeepRunning` permite conservarlo para un recorrido manual posterior. Ejecutar siempre la suite antes de ese recorrido.

Para comprobar el formulario con datos temporales, iniciar los cuatro servicios de ese proyecto y abrir [la cuenta de pruebas](http://127.0.0.1:8081/account).

```powershell
docker compose -p dawtfg-auth-tests -f compose.testing.yaml up -d --wait --wait-timeout 180
docker compose -p dawtfg-auth-tests -f compose.testing.yaml exec -T test-backend php artisan migrate --force
```

El archivo de pruebas contiene una clave de aplicación pública para esa instancia. El almacenamiento de sesión y PostgreSQL son temporales. Para detener el proyecto tras la revisión, se puede ejecutar el siguiente comando.

```powershell
docker compose -p dawtfg-auth-tests -f compose.testing.yaml down
```
