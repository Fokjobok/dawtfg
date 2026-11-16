import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

const require = createRequire(import.meta.url)

// configureServer only runs in development: these assets are absent from dist.
export function apiDocs() {
  return {
    name: 'local-api-docs',
    apply: 'serve',
    configureServer(server) {
      const files = new Map([
        ['/', [new URL('./api-docs.html', import.meta.url), 'text/html']],
        [
          '/dark.css',
          [new URL('./api-docs-dark.css', import.meta.url), 'text/css'],
        ],
        [
          '/client.js',
          [new URL('./api-docs-client.js', import.meta.url), 'text/javascript'],
        ],
        [
          '/swagger-ui-bundle.js',
          [
            require.resolve('swagger-ui-dist/swagger-ui-bundle.js'),
            'text/javascript',
          ],
        ],
        [
          '/swagger-ui.css',
          [require.resolve('swagger-ui-dist/swagger-ui.css'), 'text/css'],
        ],
        [
          '/openapi.json',
          [
            process.env.OPENAPI_FILE ||
              fileURLToPath(
                new URL('../../backend/openapi.json', import.meta.url),
              ),
            'application/json',
          ],
        ],
      ])

      server.middlewares.use('/api-docs', async (request, response, next) => {
        const pathname = new URL(request.url, 'http://localhost').pathname
        const file = files.get(pathname)
        if (!file) {
          response.statusCode = 404
          response.end('Not Found')
          return
        }
        if (!['GET', 'HEAD'].includes(request.method)) {
          response.statusCode = 405
          response.setHeader('Allow', 'GET, HEAD')
          response.end()
          return
        }
        try {
          const content = await readFile(file[0])
          response.setHeader('Content-Type', `${file[1]}; charset=utf-8`)
          response.setHeader('Cache-Control', 'no-store')
          response.end(request.method === 'HEAD' ? undefined : content)
        } catch (error) {
          next(error)
        }
      })
    },
  }
}
