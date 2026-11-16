const response = await fetch('/api-docs/openapi.json')
if (!response.ok) throw new Error('No se pudo cargar el contrato OpenAPI.')
const spec = await response.json()

// Use the current origin, including the locally configured Compose port.
spec.servers = [
  { url: window.location.origin, description: 'Desarrollo local' },
]

window.ui = window.SwaggerUIBundle({
  spec,
  dom_id: '#swagger-ui',
  validatorUrl: null,
  queryConfigEnabled: false,
  withCredentials: true,
  showMutatedRequest: false,
  requestInterceptor: async (request) => {
    const url = new URL(request.url, window.location.origin)
    if (url.origin !== window.location.origin) {
      throw new Error(
        'Esta herramienta solo permite peticiones al origen local.',
      )
    }
    request.headers.Accept = 'application/json'
    if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method.toUpperCase())) {
      // Sanctum requires a CSRF cookie before session mutations.
      const csrfResponse = await fetch('/sanctum/csrf-cookie', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
      if (!csrfResponse.ok)
        throw new Error('No se pudo preparar la protección CSRF.')
      const cookie = document.cookie
        .split(';')
        .map((part) => part.trim())
        .find((part) => part.startsWith('XSRF-TOKEN='))
      if (!cookie) throw new Error('No se recibió la cookie CSRF.')
      request.headers['X-XSRF-TOKEN'] = decodeURIComponent(
        cookie.slice('XSRF-TOKEN='.length),
      )
    }
    return request
  },
})
