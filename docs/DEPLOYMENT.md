# Preparación de producción y guía para VPS

Esta guía prepara un despliegue futuro; la aplicación no se ha desplegado públicamente. El ensayo local utiliza certificado temporal y volúmenes propios. Consultar el [seguridad del entorno](../README.md#seguridad-del-entorno) antes de ejecutar fuera de localhost.

## Arquitectura

`compose.production.yaml` es independiente del desarrollo. Usar siempre `--env-file`, `-p` y `-f` explícitos. Sus servicios tienen nombres distintos para evitar colisiones:

- `prod-nginx`: React compilado y minificado, fallback SPA, API a PHP-FPM y terminación TLS. No incluye Node ni servidor Vite en ejecución.
- `prod-backend`: código y dependencias de producción incorporados a la imagen; sin Composer, PHPUnit, tests o `.env` dentro de ella. PHP-FPM como www-data, raíz de solo lectura, OPcache sin comprobación de timestamps y cachés de configuración/rutas generadas al arrancar. No ejecuta migraciones.
- `prod-postgres`: PostgreSQL 18.6 con las correcciones locales documentadas en [seguridad del entorno](../README.md#seguridad-del-entorno). Red interna sin puerto publicado. La aplicación tiene un rol no superusuario; la administración usa `dawtfg_owner` y contraseña distinta.

Persisten PostgreSQL y `storage` en volúmenes explícitos. Mantener sus nombres al actualizar una versión. La raíz de PostgreSQL 18 es `/var/lib/postgresql`. Los secretos de entorno y las claves TLS no se incorporan al contexto de construcción ni a Git. Una persona con acceso administrativo a Docker puede leer el entorno: restringir SSH, archivos y acceso al motor.

## Variables

La [plantilla](../.env.production.example) contiene todas las variables necesarias. `PRODUCTION_APP_URL` es el origen HTTPS canónico, sin ruta; `PRODUCTION_APP_HOST` contiene solo el host. `PRODUCTION_STATEFUL_PORT_SUFFIX` se añade automáticamente si el origen usa un puerto distinto de 443. No añadir comodines CORS ni hosts stateful ajenos.

`PRODUCTION_APP_KEY`, `PRODUCTION_DB_PASSWORD` y `PRODUCTION_DB_ADMIN_PASSWORD` son secretos separados. No regenerar APP_KEY en cada arranque. Cambiar una contraseña en el archivo no modifica automáticamente un clúster PostgreSQL existente; su rotación exige un procedimiento SQL coordinado.

`PRODUCTION_RELEASE` etiqueta las imágenes; usar un identificador estable de la revisión al publicar y registrar los IDs/digests construidos. `PRODUCTION_DB_VOLUME` y `PRODUCTION_STORAGE_VOLUME` deben conservarse entre versiones y diferir de desarrollo. Un volumen con nombre existente se reutiliza: comprobar sus etiquetas y contenido antes de arrancar.

## Preparación en un VPS, para ejecutar posteriormente

Requisitos: servidor Linux actualizado, Docker Engine con Compose, capacidad de disco y memoria revisadas, acceso administrativo restringido y autorización para publicar. Los comandos Docker siguientes son compatibles con PowerShell; los scripts `.ps1` requieren PowerShell 5.1 en Windows o PowerShell 7 donde se ejecuten. No se instala nada global automáticamente.

1. Transferir una copia revisada del repositorio sin `.env`, dependencias locales ni datos. Acordar el dominio y obtener un certificado de una CA de confianza con su renovación automatizada. 
2. Guardar `fullchain.pem` y `privkey.pem` en un directorio privado del servidor. Nginx se ejecuta con UID/GID 101: debe poder leer la clave, sin dar acceso general. Montar el directorio completo, de solo lectura; comprobar permisos efectivos en Linux.
3. Generar el entorno (sustituir el dominio de ejemplo por el real):

```powershell
./scripts/Initialize-Production.ps1 -AppUrl https://training.example.test -TlsDirectory /opt/dawtfg-secrets/tls
```

El script no sobrescribe un archivo existente ni imprime secretos. Proteger `.env.production`, conservar una copia cifrada separada y revisar sus valores. Solo al autorizar una publicación real, cambiar `PRODUCTION_BIND_ADDRESS` a la interfaz pública y los puertos HTTP/HTTPS a 80/443. Los valores por defecto se limitan a loopback 8088/8443. Preparar el cortafuegos y DNS forma parte de esa publicación posterior; no se ha hecho aquí.

4. Validar y construir:

```powershell
docker compose --env-file .env.production -p dawtfg-production -f compose.production.yaml config --quiet
docker compose --env-file .env.production -p dawtfg-production -f compose.production.yaml build
docker compose --env-file .env.production -p dawtfg-production -f compose.production.yaml up -d --wait
docker compose --env-file .env.production -p dawtfg-production -f compose.production.yaml exec -T prod-backend php artisan migrate --force --no-interaction
docker compose --env-file .env.production -p dawtfg-production -f compose.production.yaml ps
```

No imprimir `config` sin `--quiet`, porque resuelve secretos. Las migraciones son una acción explícita tras revisar la versión y disponer de copia. En una actualización con datos, consultar primero [script de copia](../scripts/Backup-Production.ps1). `migrate --force` no convierte una migración destructiva en segura; revisar cada cambio de esquema.

## HTTPS, cookies y comprobaciones

HTTP redirige al origen HTTPS canónico. Nginx admite TLS 1.2/1.3, oculta versión y envía CSP, protección de tipo de contenido, política de referencia, permisos restringidos y HSTS de un día sin preload ni subdominios. Aumentar HSTS solo tras comprobar certificados/renovación. El cliente usa el mismo origen para frontend, API y CSRF. PHP recibe HTTPS por FastCGI, sin confiar en encabezados proxy arbitrarios.

Tras un despliegue futuro, verificar cadena y caducidad del certificado desde otra máquina, redirección, recursos estáticos, rutas SPA, API 200/404/405 y registro/login/logout con cookies Secure/HttpOnly/SameSite. Revisar CSP en un navegador real. Probar renovación y recarga de Nginx con `nginx -t` antes de `nginx -s reload`. No desactivar validación TLS para solventar errores.

Los healthchecks cubren PostgreSQL, proceso FPM y HTTP interno de Nginx (`/healthz`). No sustituyen el chequeo HTTPS externo, la revisión de certificados ni una consulta de disponibilidad de datos de negocio. El health de API actual comprueba el proceso Laravel.

## Operación y actualización

```powershell
docker compose --env-file .env.production -p dawtfg-production -f compose.production.yaml logs --tail 100
docker compose --env-file .env.production -p dawtfg-production -f compose.production.yaml restart
docker compose --env-file .env.production -p dawtfg-production -f compose.production.yaml stop
docker compose --env-file .env.production -p dawtfg-production -f compose.production.yaml start
```

Los logs van a stdout/stderr con controlador local rotado a tres archivos de 10MB por servicio. Nginx omite query strings en su registro de accesos. No registrar contraseñas ni cuerpos de autenticación. Para investigar, usar salidas filtradas y no compartir secretos del entorno o cookies.

Antes de actualizar: ejecutar calidad/auditorías, guardar una copia, registrar revisión/imagen actual y revisar compatibilidad de migraciones. Construir la nueva etiqueta, recrear servicios y migrar explícitamente. Una imagen anterior puede restaurar código, pero no revierte automáticamente el esquema: elegir una migración de corrección o la recuperación validada en una base/volumen nuevos. No usar `down -v` sobre datos persistentes.

No hay worker ni scheduler porque no existen tareas funcionales que los requieran. La cola sigue siendo síncrona; correo de desarrollo a logs. Configurar envío real, límites de recursos, alertas, retención y monitorización externa cuando exista el destino y se autorice su uso.

## Antes de publicar

Revisar los avisos de imágenes en [seguridad del entorno](../README.md#seguridad-del-entorno). La aceptación temporal del riesgo para desarrollo local no autoriza desplegar públicamente la aplicación. Revalidar dependencias, parches del sistema y binarios activos, completar la prueba de CI, HTTPS real, restauración en el destino y revisión de configuración. Confirmar con el tutor si VPS con Docker satisface «hosting tradicional»; no se presupone.

Fuentes: [Laravel deployment](https://laravel.com/docs/13.x/deployment), [Compose en producción](https://docs.docker.com/compose/how-tos/production/) y [TLS en Nginx](https://nginx.org/en/docs/http/ngx_http_ssl_module.html).

## Copias y recuperación

Los scripts [Backup-Production.ps1](../scripts/Backup-Production.ps1) y [Restore-ProductionDatabase.ps1](../scripts/Restore-ProductionDatabase.ps1) permiten exportar datos y restaurar en un destino nuevo. Revisar sus parámetros antes de ejecutarlos. Conservar APP_KEY y configuración de forma cifrada aparte de los datos; sin esas claves pueden quedar datos irrecuperables. Verificar la recuperación en el destino y mantener copias cifradas externas antes del despliegue público. Nunca restaurar sobre una base o volumen existente.
