# Frontend

React con JavaScript/JSX, React Router declarativo y Vite. Aplicación independiente del backend.

En Windows, ejecuta `corepack pnpm install --frozen-lockfile` desde esta carpeta. Copia `.env.example` a `.env` si aún no existe y arranca con `corepack pnpm run dev`. El [servidor Vite](http://127.0.0.1:5173/) envía `/api` al backend local en el puerto 8000. Para Docker, consulta el [README principal](../README.md).

Usa `corepack pnpm run lint`, `corepack pnpm run format:check`, `corepack pnpm test` y `corepack pnpm audit --audit-level=low` para verificar los cambios. `corepack pnpm run format` aplica formato y `corepack pnpm run build` genera `dist/`.

Los estilos globales y tokens están en `src/styles/`; los componentes usan `*.module.scss`. Sass está fijado como dependencia de desarrollo, sin instalación global. Usa los tokens y componentes existentes para mantener la coherencia visual. Desde la raíz, `node scripts/Check-Contrast.mjs` comprueba los pares de contraste de los tokens; también se ejecuta en CI.

Ver [README principal](../README.md) y [autenticación](../docs/AUTHENTICATION.md).
