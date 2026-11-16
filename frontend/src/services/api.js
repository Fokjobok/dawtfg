const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL || '/api/v1').replace(
  /\/$/,
  '',
)

export class ApiError extends Error {
  constructor(status, data) {
    super(data.error || 'Request failed')
    this.status = status
    this.errors = data.errors || {}
  }
}

export async function apiRequest(path, { method = 'GET', body, signal } = {}) {
  const headers = { Accept: 'application/json' }

  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (!['GET', 'HEAD'].includes(method)) {
    const cookie = document.cookie
      .split(';')
      .map((item) => item.trim())
      .find((item) => item.startsWith('XSRF-TOKEN='))
    if (cookie)
      headers['X-XSRF-TOKEN'] = decodeURIComponent(
        cookie.slice('XSRF-TOKEN='.length),
      )
  }

  const response = await fetch(`${apiBaseUrl}${path}`, {
    method,
    headers,
    credentials: 'same-origin',
    cache: 'no-store',
    signal: signal || AbortSignal.timeout(10000),
    body: body === undefined ? undefined : JSON.stringify(body),
  })

  if (response.status === 204) return null
  const data = await response.json()
  if (!response.ok) throw new ApiError(response.status, data)
  return data
}

export async function getHealth() {
  const health = await apiRequest('/health')

  if (health.status !== 'ok') {
    throw new Error('Unexpected health response')
  }

  return health
}
