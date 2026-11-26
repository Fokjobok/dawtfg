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

El guardado devuelve además `meta.updated`: solo es `true` cuando se ha guardado un cambio efectivo. Un envío con los mismos valores, retirada de un avatar inexistente o archivo con los mismos bytes devuelve `200` con `false`, sin escritura, cambio de fechas o consumo de guardados. El cliente debe detectar los cambios antes de enviar y no presentar un éxito de actualización ante `false`.

Cada cuenta dispone de bloques de tres guardados efectivos del perfil; el tercero inicia una pausa de 60 segundos. El estado se conserva en `users.profile_save_count` y `users.profile_save_available_at`, fuera de los campos editables y de la identidad pública/de sesión. Se comprueba bajo bloqueo de fila en la misma transacción que el guardado: cambiar de sesión, recargar o llamar directamente a la API no elimina la pausa. Los errores y los envíos sin cambios no consumen ni reinician el bloque. Tras la pausa comienza otro bloque de tres. Una nueva modificación durante la pausa devuelve `429`, cabecera `Retry-After` y cuerpo `{ "error": "Too Many Requests", "code": 429, "retry_after": 60 }` con los segundos restantes; no guarda datos ni archivos.

Solo el propietario recibe `data.save_limits` en GET/HEAD del perfil propio, en su ficha GET/HEAD pública y en el resultado de PATCH: `remaining_updates` (0–3), `retry_after` (segundos restantes, 0 si puede guardar) y `available_at` (fecha UTC de fin del bloqueo, o null). No se entrega a terceros ni en `/session`. El cliente muestra una cuenta atrás junto al botón, permite editar durante la pausa y bloquea únicamente el envío; la API sigue siendo la autoridad. Las actualizaciones de cuenta por otras rutas administrativas no consumen este bloque de edición propia.

GET/HEAD `/api/v1/profiles/{user}/avatar` es público incluso si el perfil es privado; 404 si usuario o avatar no existen. Sirve MIME real y cabeceras nosniff/CSP/no-store/private, sin directorio público genérico. avatar_url cambia de versión al reemplazar el archivo. El modo inicial es público, sin biografía ni avatar; la ampliación de privacidad por sección conserva los datos y ajustes existentes. El listado propio de inscripciones se describe más abajo; el progreso sigue pendiente.

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


## Inscripciones propias y cursos destacados

POST `/api/v1/courses/{course}/enrollments` requiere Student, sesión web y CSRF. Solo admite nuevas inscripciones en cursos gratuitos (`price=0.00`) que cumplan el mismo alcance del catálogo público; borradores/incompletos devuelven 404 y cursos con precio 422. El usuario se toma de la sesión, nunca del cuerpo. No se aceptan user_id, course_id, price, status o featured_order. Devuelve 201/data y Location para una inscripción nueva; repetir devuelve 200 con la misma fila. Bloqueos user -> course y unicidad SQL evitan duplicados; el rol y el estado/precio actuales se revalidan en el servidor. No hay cobros ni nuevos permisos de gestión del curso.

GET/HEAD `/api/v1/enrollments` lista solo las inscripciones propias, con page/per_page (1–100), id descendente y filtro opcional `filter[course_ids][]` (hasta 100 IDs distintos), útil para resolver en una petición el estado de las tarjetas de una página del catálogo. GET/HEAD `/api/v1/enrollments/{enrollment}` consulta una propia. Cada elemento tiene id, course_id, enrolled_at, featured_order y course, que contiene únicamente el recurso público del curso o null cuando ya no es público. No se filtran títulos/portadas de borradores. No hay acceso privilegiado a inscripciones ajenas para Administrator. Todas las respuestas personales son no-store/private.

DELETE `/api/v1/enrollments/{enrollment}` requiere propietario, sesión y CSRF; confirma baja con 204, sin borrar usuario/curso. Si el rol cambió después del alta, el propietario conserva consulta y baja de sus inscripciones previas. Curso eliminado elimina su inscripción por FK; curso despublicado la conserva para que pueda retirarse, sin publicar sus detalles. La baja elimina también su selección destacada y no consume ni reinicia el cooldown del perfil.

PATCH `/api/v1/profile` añade `enrollments_is_private` (booleano) y `featured_course_ids` (array ordenado de hasta tres IDs distintos de cursos con inscripción propia). [] vacía la selección. En multipart se admite array habitual o cadena JSON de hasta 128 bytes, incluido []. Omitir estos campos conserva sus valores. Cambiar privacidad/selección cuenta como una actualización efectiva del perfil y respeta su límite; repetir la misma selección es no-op. La transacción conserva orden, membresía y contador, y revierte todo ante fallo. Rango 1–3 y unicidad SQL por usuario/posición refuerzan el máximo de tres.

GET/HEAD del perfil añade `featured_courses`, limitado a las tarjetas públicas seleccionadas y ordenadas. El propietario siempre puede consultar sus destacados públicos actuales y recibe además featured_course_ids/enrollments_is_private. A terceros solo se entrega featured_courses si el perfil es público y enrollments_is_private=false; en los demás casos se omite por completo, también para otro Administrator, sin revelar IDs o recuentos privados. La privacidad global prevalece sin borrar la preferencia de sección. La lista completa de inscripciones permanece propia; la sección pública es una selección voluntaria, no su historial completo. No se exponen estos datos en /session ni se inventan porcentajes de progreso.


### Identidad del Coach en tarjetas públicas

El objeto coach de PublicCourse añade avatar_url nullable/versionado junto con id/name. Se utiliza en catálogo, cursos públicos de las inscripciones propias y destacados del perfil. El avatar sigue siendo público aunque el perfil del docente sea privado; no se exponen correo, biografía, rutas internas o medallas ficticias. Los selectores de docentes y los colaboradores de los recursos de gestión conservan su contrato id/name.


## Medallas por especialidad

Administrator crea definiciones mediante POST /api/v1/medals, con name (recortado, 1–100 caracteres y único por especialidad), specialty_id existente e image opcional en multipart. Sin imagen admite JSON. La categoría/juego reutiliza specialties. Imagen JPG/PNG/WebP hasta 1024 KiB y máximos independientes 512×512; disco privado con nombre generado, sin SVG/GIF ni rutas aportadas por cliente. Creación 201, validación/duplicado 422; crear no concede la medalla a nadie. GET/HEAD /api/v1/medals ofrece catálogo administrativo paginado (page/per_page, 1–100), filtro filter[specialty_id] y orden nombre/ID. Cada medalla contiene id, name, specialty e image_url nullable, sin rutas internas.

GET/HEAD /api/v1/medal-recipients es un selector administrativo paginado de cuentas de todos los roles, incluidos administradores, con id/name/role y filtro opcional filter[name]. No modifica el listado habitual de usuarios que excluye Administrator. GET/HEAD /api/v1/users/{user}/medals consulta asignaciones administrativas paginadas, ordenadas por nombre/ID y con filtro opcional de especialidad; no constituye lectura privilegiada de su perfil privado.

PUT /api/v1/users/{user}/medals/{medal}, sin cuerpo, asigna a una cuenta concreta de cualquier rol. Devuelve 200 con la medalla, también al repetir; una única asociación conserva sus fechas originales. DELETE de esa misma URI retira la asignación con 204 aunque ya no existiera. Ambos requieren Administrator, sesión y CSRF incluso sin Origin; usuario/medalla inexistentes 404, sin sesión 401, rol no autorizado 403 y CSRF inválido 419. Bloqueo de usuarios por ID y luego de medalla, con revalidación del rol actual. Asignar/retirar no consume el cooldown de edición propia ni borra la definición o su imagen. No hay concesión automática masiva.

GET/HEAD /api/v1/medals/{medal}/image entrega únicamente el dibujo público de la definición; no revela quién lo posee. 404 si no existe imagen/medalla. MIME real, inline, no-store/private, nosniff y CSP restrictiva, sin acceso genérico al disco.

PublicCourse.coach añade medals solo si el perfil del Coach y su sección son públicos; devuelve únicamente sus medallas de la especialidad del curso, por ID ascendente. En los demás casos se omite, sin IDs/recuentos ocultos, incluso en el catálogo del propio Coach. Misma regla en tarjetas de inscripciones y destacados. Perfil propio devuelve medals de todas las especialidades y medals_is_private; terceros reciben medals solo si lo permiten la privacidad global y la sección, sin excepción para otros administradores. PATCH profile acepta medals_is_private, conserva preferencias al alternar privacidad global y respeta no-op/cooldown. No permite autoasignar con medals/medal_ids ni expone medallas en session. La administración React incluye creación y asignación/retirada a una cuenta seleccionada. CoachCard muestra las medallas permitidas; el editor propio permite ajustar su privacidad.


## Categoría -> videojuego o materia -> nivel

GET/HEAD /api/v1/categories lista todas las categorías para Administrator (page/per_page, 1–100, orden nombre/ID). POST /api/v1/categories recibe name recortado único de 1–100 caracteres, sesión/CSRF, y devuelve 201 con data.id/name. 401 sin sesión, 403 sin rol, 419 sin CSRF, 422 por validación o carrera de unicidad. Crear no modifica especialidades ni cursos.

PATCH /api/v1/specialties/{specialty} clasifica una especialidad existente, solo Administrator, con category_id obligatorio que admite ID existente o null. Respuesta 200 con id/name/category nullable, no-store/private. No permite renombrar por esta ruta ni cambia docentes, cursos o cooldown del perfil. Rol actual revalidado bajo bloqueo usuario -> especialidad. Repetir la asignación conserva updated_at. Referencia inválida 422, especialidad inexistente 404, además de 401/403/419. POST specialties también admite category_id opcional; GET specialties/POST specialties y sus opciones públicas añaden category nullable. Los DTO id/name utilizados en cursos, medallas y usuarios permanecen sin ampliar.

GET/HEAD /api/v1/catalog/categories es público y solo devuelve categorías con cursos que cumplan el alcance público a través de sus especialidades. Vacías, con solo borradores o responsables no válidos se omiten. GET/HEAD /api/v1/catalog/specialties admite filter[category_id] y devuelve id/name/category con la misma restricción pública. Sin filtro conserva juegos o materias sin clasificar. Nombres administrativos no implican que deban aparecer como opciones públicas antes de clasificar al menos una especialidad con cursos visibles.

GET/HEAD /api/v1/courses incorpora filter[category_id], combinado mediante AND con filter[specialty_id] y filter[level] antes de paginar. ID positivo inexistente o combinación incompatible devuelve lista vacía; ID inválido 422. Orden de publicación actual por ID descendente y resto de permisos de catálogo/inscripciones/portadas sin cambios. Los tres filtros son opcionales; omitirlos sigue mostrando todos los cursos públicos, incluidos los no clasificados.
