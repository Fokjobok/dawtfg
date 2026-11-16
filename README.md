# Formación y coaching — base del TFG DAW

React y Laravel son aplicaciones independientes que se comunican mediante HTTP. Esta base incluye navegación, autenticación de estudiantes, roles, API documentada, pruebas, estilos propios y entornos Docker separados.

La base está preparada para desarrollo local. Incluye un panel inicial de administración con listado por rol y edición básica de usuarios, y el esquema de cursos y especialidades. La creación de cursos, el formulario de especialidades, cuestionarios e inscripciones siguen pendientes. Consulta las [seguridad del entorno](#seguridad-del-entorno) antes de preparar un despliegue público.

El panel está en `/admin` y requiere una cuenta Administrator; muestra Coaches y estudiantes después de elegir su rol, excluyendo administradores. Consulta el [contrato de la API](backend/openapi.json) y el [modelo de cursos/especialidades](docs/COURSE_DATA_MODEL.md). El formulario inicial de cursos en `/courses/create` todavía no guarda cursos.

## Arrancar el entorno de desarrollo

Necesitas Windows 11, WSL2 y Docker Desktop con motor Linux, además de una terminal PowerShell autorizada para ejecutar los scripts del proyecto. Se verificó PowerShell 7.6.9, Docker Engine 29.9.3 y Compose 5.7.0. No necesitas instalar PHP, Composer, Node o PostgreSQL en Windows para este procedimiento.

Desde la carpeta que contiene este README y `compose.yaml`:

```powershell
docker version
docker compose version
./scripts/Initialize-Development.ps1
docker compose config --quiet
docker compose build
docker compose up -d --wait --wait-timeout 180
docker compose exec -T backend php artisan migrate --no-interaction
docker compose ps
Invoke-RestMethod http://localhost:8080/api/v1/health
```

Abre [el inicio](http://localhost:8080/) y [Mi cuenta](http://localhost:8080/account). Puedes registrar una cuenta Student, entrar y cerrar sesión. Para asignar Coach o Administrator a una cuenta existente, consulta la [guía de autenticación](docs/AUTHENTICATION.md). El registro público no acepta un rol privilegiado.

El script genera `.env` en la raíz y conserva las claves existentes al repetirlo. Las primeras construcciones necesitan Internet y pueden tardar; no hay migraciones ni usuarios de demostración automáticos. Revisa los códigos de salida de cada comando antes de continuar.

## Configuración y persistencia

| Archivo o variable | Uso |
| --- | --- |
| `.env` raíz | Exclusivamente Compose de desarrollo; generado localmente, ignorado por Git |
| `COMPOSE_PROJECT_NAME=dawtfg` | Identidad del entorno y sus volúmenes; mantenerla estable |
| `APP_PORT=8080` | Único puerto publicado, solo en loopback |
| `APP_KEY` | Clave privada de Laravel; no regenerarla para reiniciar |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Base y credenciales del volumen de desarrollo |
| `VITE_USE_POLLING=true` | Detección de cambios en montajes Windows |
| `frontend/.env` y `backend/.env` | Solo para el arranque nativo independiente; Docker inyecta sus propios valores |
| `.env.production` | Entorno productivo separado, con claves y volúmenes propios |

En la configuración base, frontend, PHP-FPM y PostgreSQL no exponen puertos directos. Nginx sirve el origen común; usa siempre `localhost` para la sesión. La cookie de desarrollo deriva del nombre del proyecto para separar instancias sobre el mismo host. Las variables `VITE_` llegan al navegador y nunca deben contener secretos.

PostgreSQL conserva datos en `dawtfg_postgres_data`; dependencias y almacenamiento Laravel también usan volúmenes. PostgreSQL 18 monta `/var/lib/postgresql`. Cambiar DB_PASSWORD en el archivo no rota una contraseña de una base ya inicializada.

No compartas `.env`, claves, cookies ni la salida resuelta de `docker compose config`. Usa `config --quiet`. Las carpetas `.cache/`, `.secrets/`, `.backups/`, dependencias y builds están excluidos de Git.

## Conectar HeidiSQL a desarrollo (opcional)

Para consultar PostgreSQL desde Windows, añade [compose.heidi.yaml](compose.heidi.yaml) a la configuración de desarrollo:

```powershell
docker compose --env-file .env -p dawtfg -f compose.yaml -f compose.heidi.yaml config --quiet
docker compose --env-file .env -p dawtfg -f compose.yaml -f compose.heidi.yaml up -d --wait --wait-timeout 180
```

En HeidiSQL crea una sesión PostgreSQL TCP/IP: host `127.0.0.1`, puerto `15432`, usuario y base indicados por `DB_USERNAME` y `DB_DATABASE` en el `.env` raíz. Copia `DB_PASSWORD` directamente de ese archivo al campo de contraseña; no lo compartas. El esquema de tablas de la aplicación es `public`. Modifica la estructura mediante migraciones Laravel.

El puerto se publica solo en loopback; no se instala otro servidor PostgreSQL ni se cambian volúmenes. El complemento añade una red bridge exclusiva de PostgreSQL: Docker no publicaba el puerto mientras el servicio pertenecía únicamente a la red interna. `DB_FORWARD_PORT` permite elegir otro puerto local si fuera necesario. Al recrear el entorno con este acceso, incluye ambos `-f`; Docker Desktop puede parar y arrancar los contenedores ya creados. Para retirar el acceso, ejecuta `docker compose --env-file .env -p dawtfg -f compose.yaml up -d --wait --wait-timeout 180`. No uses este complemento con producción ni pruebas.

Si Windows rechaza el puerto web 8080, modifica `APP_PORT` en el `.env` raíz (en este equipo se usa 18080) y ejecuta `up` para aplicar el cambio; `start` conserva los puertos anteriores.

## Trabajo diario, parada y reinicio

El código se monta desde Windows y Vite recarga los cambios. Tras modificar una dependencia o Dockerfile, reconstruye y recrea los servicios.

```powershell
docker compose logs --tail 60
docker compose exec -T backend php artisan migrate:status
docker compose exec -T backend php artisan migrate --no-interaction
docker compose stop
docker compose start --wait
```

Para reconstruir y recrear conservando datos:

```powershell
docker compose build
docker compose up -d --wait --wait-timeout 180
```

`docker compose down` también conserva los volúmenes si no se añade `--volumes`. No uses `down --volumes`, `volume prune` ni `migrate:fresh` para reiniciar desarrollo. Revisa cualquier migración antes de ejecutarla sobre datos que quieras conservar.

## Formato, pruebas y build

Todos los comandos siguientes funcionan desde la raíz, con desarrollo arrancado:

```powershell
docker compose exec -T frontend corepack pnpm run format:check
docker compose exec -T frontend corepack pnpm run lint
docker compose exec -T frontend corepack pnpm test
docker compose exec -T frontend corepack pnpm run build
docker compose exec -T backend composer validate --strict
docker compose exec -T backend composer check-platform-reqs
docker compose exec -T backend composer run format:check
docker compose exec -T backend composer test
```

Para aplicar formato: `docker compose exec -T frontend corepack pnpm run format` y `docker compose exec -T backend composer run format`. El build frontend genera `frontend/dist/`; el despliegue usa una etapa de compilación propia.

PHPUnit usa SQLite en memoria y una clave independiente por proceso. Para ejecutar la misma suite sobre PostgreSQL aislado:

```powershell
docker compose build backend postgres
./scripts/Test-Backend.ps1
```

El script comprueba los códigos de salida, usa `dawtfg-auth-tests` y detiene ese entorno al terminar, también ante fallo. `-KeepRunning` lo conserva para revisión manual. No apuntes los tests a una base de desarrollo. La [guía de autenticación](docs/AUTHENTICATION.md) explica el recorrido manual y su limpieza.

La auditoría final ejecutó 32 pruebas Laravel en SQLite y PostgreSQL y siete pruebas React. El [workflow Quality](.github/workflows/quality.yml) prepara estos controles para GitHub; su primera ejecución remota sigue pendiente. El contraste de tokens se comprueba en CI y, si tienes Node local, mediante `node scripts/Check-Contrast.mjs`.

## Dependencias y seguridad

Para consumir la API desde el navegador, abre **`/api-docs/`** en el mismo origen de la web (actualmente [http://localhost:18080/api-docs/](http://localhost:18080/api-docs/) en este equipo). Swagger UI permite ejecutar las operaciones documentadas con **Try it out → Execute**. Solo se sirve en desarrollo. Consulta la [guía de API](docs/API_GUIDE.md) para sesiones y CSRF.

```powershell
docker compose exec -T frontend corepack pnpm install --frozen-lockfile
docker compose exec -T frontend corepack pnpm audit --audit-level=low
docker compose exec -T backend composer audit --locked
docker compose exec -T backend composer --working-dir=/opt/composer audit --locked
```

Conserva los lockfiles y no los edites manualmente. pnpm está fijado con integridad, siete días mínimos de antigüedad y scripts de dependencias restringidos. Hay excepciones temporales para versiones exactas previamente auditadas: revisarlas sin ampliarlas después del 16 de noviembre de 2026 a las 12:00 Europe/Madrid.

Las auditorías de paquetes no cubren todo el sistema operativo de los contenedores. Persisten avisos en imágenes, aceptados temporalmente para desarrollo local; esa aceptación no permite desplegar públicamente la aplicación. Las correcciones locales de libxml2/Kerberos deben revisarse antes de sustituirlas por paquetes oficiales. Consulta el [seguridad del entorno](#seguridad-del-entorno).

## Estructura y convenciones

| Carpeta | Responsabilidad |
| --- | --- |
| `frontend/` | React JavaScript/JSX, React Router, Vite, SCSS y CSS Modules |
| `backend/` | API Laravel, Sanctum con sesiones/CSRF, Eloquent, validaciones, Policies y pruebas |
| `infra/` | Docker, Nginx, PHP y construcción de bibliotecas corregidas |
| `scripts/` | Inicialización, pruebas aisladas, contraste, copias y recuperación |
| `docs/` | Documentación de API, autenticación, modelo de datos y despliegue |
| `.github/workflows/` | CI preparada, pendiente de ejecución remota |

React usa JavaScript y JSX; la API conserva las convenciones de Laravel. Añade controladores, Form Requests, servicios y Policies cuando tengan una responsabilidad real. Consulta [API_GUIDE.md](docs/API_GUIDE.md) y [OpenAPI](backend/openapi.json) antes de ampliar la base.

Versiones fijadas/verificadas: Node 24.22.2, pnpm 12.19.0, React 19.3.1, Vite 8.3.7, Sass 1.107.1, PHP 8.5.12, Laravel 13.40.0, Sanctum 4.4.0 y PostgreSQL 18.6. El Composer reconstruido dentro de Docker usa su lock corregido.

## Base visual

Tema oscuro, azules empresariales y acentos verdes. Los colores, la tipografía y el espaciado se definen en `frontend/src/styles/_tokens.scss`; los componentes utilizan SCSS Modules. La guía de estilos y el prototipo definitivos están pendientes de elaboración.

El prototipo Figma y la auditoría completa de accesibilidad siguen pendientes. Las comprobaciones actuales de contraste, teclado y responsive no equivalen a certificación WCAG integral.

## Producción preparada

Consulta [DEPLOYMENT.md](docs/DEPLOYMENT.md) y [scripts de copia y recuperación](scripts/Backup-Production.ps1). `compose.production.yaml` sirve React compilado mediante Nginx con HTTPS, PHP-FPM y PostgreSQL; no utiliza Vite en runtime. Tiene variables, roles SQL y volúmenes propios. Usar siempre `--env-file`, `-p` y `-f` explícitos.

Las copias y restauraciones se ensayaron con datos ficticios en destinos nuevos. Certificado público, renovación, VPS, copias cifradas externas y exposición pública requieren trabajo y autorización posteriores. No reutilices secretos o volúmenes de desarrollo ni restaures sobre un destino existente.

## Arranque nativo opcional

La separación de aplicaciones permite ejecutar [frontend](frontend/README.md) y [backend](backend/README.md) sin Docker si dispones de sus herramientas y una base PostgreSQL propia. Para el flujo completo de autenticación, la API necesita base de datos; el endpoint de salud no la comprueba. No se publica el PostgreSQL interno de Docker para este procedimiento.

Comprueba la versión y auditoría de PHP y Composer nativos antes de usarlos. Las comprobaciones del entorno Docker no cubren las herramientas instaladas en Windows.

## Diagnóstico habitual

| Problema | Comprobación |
| --- | --- |
| Docker inaccesible | Abrir Docker Desktop, esperar al motor Linux, ejecutar `docker version` y `docker context ls` |
| Un script PowerShell está bloqueado | Revisar la política de ejecución aplicable con el administrador. Usar una terminal PowerShell autorizada para ejecutar los scripts |
| Puerto 8080 ocupado | Cambiar APP_PORT en `.env` y recrear con `docker compose up -d --wait` |
| Servicio unhealthy | Revisar `docker compose ps` y `logs --tail 100`; la primera instalación necesita red |
| Sesión no persiste o aparece 419 | Usar el mismo host y origen, comprobar cookies/CSRF y APP_KEY; no abrir la SPA con hosts alternos sin configurar Sanctum |
| Error 422 al acceder/registrarse | Revisar los errores por campo; bcrypt admite 72 bytes y se rechazan bytes nulos tanto en registro como en acceso |
| Error 429 | Esperar el tiempo de Retry-After; login y registro tienen límite de intentos |
| PostgreSQL rechaza credenciales | Comprobar el volumen y credenciales iniciales; editar `.env` no cambia una contraseña existente |
| Cambios no aparecen | Revisar VITE_USE_POLLING; recrear si cambian variables y reconstruir si cambian imágenes/dependencias |
| APT no encuentra una fuente fijada | Revisar los scripts de backport y los paquetes oficiales disponibles; no quitar comprobaciones ni ignorar versiones |
| Instalación pnpm rechazada por antigüedad/scripts | Revisar la política y el paquete concreto; no añadir excepciones amplias ni aprobar todos los scripts |
| CVE en una imagen | Revisar el informe bruto y su clasificación; distinguir paquete fuente, backport y binario activo |

Antes de añadir recursos educativos, define su alcance y modelo de datos. Mantén sincronizados el contrato OpenAPI, las validaciones y las pruebas.

## Seguridad del entorno

La revisión de imágenes del 12 de noviembre de 2026 dejó 60 CVE únicos abiertos: seis de severidad media y 54 de severidad baja. El riesgo se aceptó temporalmente para desarrollo local; el despliegue público requiere una nueva evaluación. Las auditorías de pnpm y Composer no cubren todos los paquetes del sistema operativo.

Las imágenes construyen correcciones locales de libxml2 y Kerberos y reconstruyen Composer y gosu con dependencias fijadas. Sus fuentes y parches están en `infra/toolchain/`. Revisar las actualizaciones oficiales antes de sustituirlos y repetir las auditorías tras cada cambio. Los secretos, certificados privados y copias de datos deben mantenerse fuera de Git.
