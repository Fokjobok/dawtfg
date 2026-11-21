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

GET/HEAD `/api/v1/session` es una consulta opcional para la SPA, también disponible sin iniciar sesión: 200 con `data:null` para visitantes o la identidad propia para una sesión válida, con respuesta privada no almacenable. No sustituye a GET `/api/v1/user` ni a la autorización de recursos; las rutas protegidas conservan 401/403. [Detalle y estado de integración React](AUTHENTICATION.md).

Las tablas no crean endpoints automáticamente: `courses` dispone de creación, catálogo público y consulta/edición privada para los docentes autorizados o Administrator. [Campos y reglas](COURSE_DATA_MODEL.md).

- Registrar las rutas en `backend/routes/api.php` dentro del grupo `/api/v1`. Una ruptura de contrato requiere una versión nueva.
- Usar nombres de recurso en plural y segmentos en `kebab-case`. Usar identificadores enteros cuando correspondan al modelo. Anidar una relación cuando el padre determine el alcance y comprobar esa relación en el servidor.
- Usar `GET` para lectura, `POST` para crear, `PUT` para reemplazar, `PATCH` para cambios parciales y `DELETE` para eliminar. Responder con `200` para lecturas y escrituras con representación, `201` para creaciones y `204` cuando corresponda devolver una respuesta vacía. Para una creación con URI de consulta, enviar `Location`.
- Usar Laravel API Resources para serializar las entidades cuando existan. Las colecciones paginadas usarán `data`, `links` y `meta` del paginador de Laravel.

## Colecciones disponibles

Especialidades: GET/HEAD `/api/v1/specialties` paginado. Administrator consulta todo el catálogo y Coach únicamente sus asignaciones; Student no tiene acceso. POST `/api/v1/specialties` crea un nombre único y sigue reservado a Administrator, con cookies/CSRF. Crear la especialidad no la asigna a nadie; la asociación se hace editando un usuario de rol Coach. Un estudiante no puede recibir especialidades mientras conserve ese rol. [Contrato OpenAPI](../backend/openapi.json).

Cursos: POST `/api/v1/courses` para Coach o Administrator con cookies/CSRF, recibe título, descripción, especialidad, precio, responsable seleccionable `coach_id` y opcionalmente `additional_coach_ids`. Administrator debe elegir responsable; si Coach lo omite, se utiliza él mismo. Todos los docentes deben ser Coaches de esa especialidad y un creador Coach debe pertenecer a ella. Admite status draft/published elegido al crear, con draft si se omite. Admite JSON sin portada o multipart/form-data con cover JPG/PNG/WebP opcional, hasta 2048 KiB, 4096 píxeles de ancho y 2048 de alto. Devuelve 201 con curso, docentes y cover_url o null. [Modelo y límites](COURSE_DATA_MODEL.md).

Catálogo: GET/HEAD `/api/v1/courses` público, paginado y ordenado por id descendente (creación más reciente). Solo publicados con especialidad y responsable válidos/asignados. Datos de tarjeta: id, título, descripción, nivel, precio, especialidad y responsable (id/nombre) y cover_url o null. No devuelve borradores, correos ni rutas internas. page/per_page mantienen los límites de la convención general. No hay aún inscripción ni sistema de pagos.

Niveles: `beginner`, `advanced` y `expert`. POST courses acepta `level` opcional por compatibilidad con clientes anteriores; si se incluye debe ser uno de esos valores, sin null ni cadena vacía. Si se omite, el curso queda sin clasificar y la respuesta incluye level=null. Los cursos existentes no reciben un nivel inventado. GET courses admite `filter[specialty_id]` y `filter[level]`, combinables y aplicados antes de paginar. Sin filtros se conserva el catálogo completo visible, incluidos los cursos sin nivel; al filtrar por nivel solo se incluyen coincidencias exactas. Valores inválidos devuelven 422 y una especialidad inexistente devuelve una colección vacía.

Opciones del filtro de especialidad: GET/HEAD `/api/v1/catalog/specialties`, público, paginado y ordenado por nombre/id. Devuelve únicamente id/nombre de especialidades con algún curso visible en el catálogo; no incluye grupos vacíos o privados. La gestión GET/POST `/specialties` conserva sus permisos anteriores. Cambiar filtros en React debe restablecer la página a 1; la API conserva los filtros en los enlaces de paginación.

Selección de responsable/colaboradores: GET/HEAD `/api/v1/specialties/{specialty}/coaches`, paginado por nombre/id, devuelve solo id y nombre. Coach consulta sus propios grupos y ve todos sus Coaches, incluido él mismo; Administrator consulta cualquier grupo. Student no tiene acceso. Una especialidad ajena al Coach devuelve 403, una inexistente 404 y una paginación inválida 422.

Gestión privada: GET/HEAD `/api/v1/teaching/courses` lista cursos donde el Coach es responsable o colaborador; Administrator ve todos, incluidos borradores. Orden id descendente y paginación habitual. GET/HEAD `/api/v1/courses/{course}` consulta un curso para editarlo con los mismos permisos. Incluye datos de Course, nombres mínimos de responsable/especialidad y las capacidades can_change_responsible/can_delete. No es una ficha pública ni un listado de inscripciones; respuestas no-store/private.

Edición: PATCH `/api/v1/courses/{course}`, con sesión y CSRF, acepta cambios parciales de título, descripción, nivel, precio, estado, especialidad, responsable, colaboradores y portada. Omitir campos conserva sus valores. Un colaborador puede editar los demás datos, pero no sustituir al responsable; solo este o Administrator pueden hacerlo. Un editor Coach elige una especialidad propia y todos los docentes deben pertenecer a ella. additional_coach_ids=[] vacía colaboradores sin quitar al responsable. El equipo se revalida en la transacción y un cambio concurrente del equipo devuelve 409 para recargar. El borrado tiene permisos separados del permiso de edición.

Portadas: GET/HEAD `/api/v1/courses/{course}/cover`, público para los cursos que cumplan el alcance del catálogo. Para borradores y cursos incompletos mantiene autorización de Administrator o sus docentes. Devuelve MIME real, nosniff y no-store/private. No hay directorio público ni ruta de archivos genérica. Para reemplazar al editar se usa multipart mediante POST a la URI del curso con _method=PATCH y CSRF; JSON PATCH sirve para cambios sin archivo. remove_cover=true retira la imagen y no se combina con cover. En multipart se admite additional_coach_ids como array JSON de hasta 4096 bytes, también [] para vaciarlo junto con una subida. El archivo anterior se elimina después del guardado; ante fallo se conserva y se limpia el reemplazo.

POST courses devuelve ahora Location con la URI privada de consulta. La cabecera no concede acceso al creador si eligió a otro responsable y no participa como colaborador.

Primera colección implementada: `GET /api/v1/users`, exclusiva de Administrator, con paginación, filtro por rol y ordenación. `PATCH /api/v1/users/{user}` permite edición administrativa parcial con cookies y CSRF. [Contrato OpenAPI](../backend/openapi.json). Ambas operaciones están disponibles en Swagger.

Cada operación de listado declarará en OpenAPI los parámetros que admite. Como convención inicial, `page` empieza en 1 y `per_page` tiene un valor predeterminado de 20 y un máximo de 100. Los filtros usan `filter[campo]` y la ordenación usa `sort=campo` o `sort=-campo`. Validar nombres, valores y límites con una lista permitida por recurso. Ante campos de orden iguales, usar el identificador como desempate para obtener páginas deterministas. Los parámetros inválidos de una operación que los admita devuelven `422`.

## Perfiles

La identidad propia de sesión/login/registro/GET user incluye avatar_url nullable y versionada, para reutilizar el avatar en la cuenta y cabecera sin consultar la biografía. Usa la misma ruta pública específica y el mismo cambio de versión al reemplazarlo. El visitante conserva data=null en GET/HEAD session; esta ampliación no altera permisos o políticas de privacidad.

GET/HEAD `/api/v1/profiles/{user}` es público. Devuelve data con id, name, level, avatar_url (o null), is_private y can_edit. Nombre, avatar y nivel numérico general siempre públicos. bio solo aparece para el propietario o cuando is_private=false y bio_is_private=false; se omite completamente para terceros en los demás casos, también Administrator. bio_is_private solo se devuelve al propietario. No expone correo, rol, credenciales ni rutas internas. La sesión web opcional identifica al propietario incluso sin Origin; las respuestas usan no-store/private.

GET/HEAD `/api/v1/profile` consulta el perfil propio con sesión, incluidos bio y bio_is_private. PATCH a la misma URI lo edita con sesión y CSRF, para cualquier rol. Campos parciales: name (1–100 caracteres), bio (hasta 2000 o null), is_private, bio_is_private y remove_avatar (booleanos). La privacidad general prevalece sobre la sección, sin borrar su preferencia. Debe incluir algún campo editable; no permite cambiar nivel, experiencia, rangos, correo, rol, credenciales, identificadores o especialidades. Devuelve 200 con el perfil propio, 401 sin sesión, 419 sin CSRF válido y 422 ante validación fallida.

Para subir avatar, enviar POST multipart a `/api/v1/profile` con `_method=PATCH`, avatar JPG/PNG/WebP hasta 2048 KiB, ancho máximo 1024 y alto máximo 1024, sin proporción obligatoria. En multipart, is_private/bio_is_private/remove_avatar se envían como 0/1; JSON usa booleanos. No combinar remove_avatar=1 y archivo. El anterior se limpia tras confirmar el guardado; si falla la base se conserva y se limpia el reemplazo. Un fallo posterior de limpieza se registra sin falsear el guardado confirmado.

GET/HEAD `/api/v1/profiles/{user}/avatar` es público incluso si el perfil es privado; 404 si usuario o avatar no existen. Sirve MIME real y cabeceras nosniff/CSP/no-store/private, sin directorio público genérico. avatar_url cambia de versión al reemplazar el archivo. El modo inicial es público, sin biografía ni avatar; la ampliación de privacidad por sección conserva los datos y ajustes existentes. No hay todavía listado de inscripciones o progreso.

Nivel general inicial 1 para usuarios nuevos y anteriores; no se calcula experiencia ni hay ascensos automáticos. Es independiente de los niveles de cursos y de los futuros rangos por materia (principiante/avanzado/experto), todavía sin asignación. Las secciones de cursos inscritos, amigos y logros y sus preferencias se incorporarán cuando exista cada función.

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

## Borrado e identificación de cursos

DELETE `/api/v1/courses/{course}` requiere sesión y CSRF. Solo el Coach responsable actual o cualquier Administrator pueden borrar; un colaborador recibe 403 aunque pueda editar. Devuelve 204 sin cuerpo y no-store/private, 401 sin sesión, 403 sin permiso, 404 si no existe y 419 sin CSRF válido. Se revalida al actor y al responsable con bloqueo antes de borrar.

El borrado es definitivo: elimina el curso y sus asociaciones de colaboradores, conservando usuarios, especialidades y otros cursos. La portada se elimina después de confirmar la transacción. Si falla la base, se conservan curso y archivo; si falla la limpieza posterior, se registra para revisión y se mantiene el 204 del borrado ya confirmado. No se afirma atomicidad distribuida ni restauración automática.

El campo id existente es el número estable de curso, compartido por catálogo y gestión. No se crea otro contador ni se renumeran cursos tras borrar. ManagedCourseResource devuelve can_delete para mostrar la acción correspondiente; ese indicador de interfaz no sustituye la Policy del servidor. Las miniaturas reutilizan cover_url y se limitan visualmente con CSS, sin generar archivos derivados.
