import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

let options

beforeEach(async () => {
  vi.resetModules()
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        openapi: '3.1.1',
        servers: [{ url: 'http://localhost:8080' }],
        paths: {},
      }),
    }),
  )
  window.SwaggerUIBundle = vi.fn((configuration) => {
    options = configuration
  })
  await import('./api-docs-client.js')
})

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/'
  delete window.SwaggerUIBundle
  delete window.ui
  vi.unstubAllGlobals()
})

describe('local Swagger requests', () => {
  it('uses the current origin and disables external validation', () => {
    expect(options.spec.servers[0].url).toBe(window.location.origin)
    expect(options.validatorUrl).toBeNull()
    expect(options.queryConfigEnabled).toBe(false)
  })

  it('sends JSON headers on reads without preparing a CSRF cookie', async () => {
    fetch.mockClear()
    const request = await options.requestInterceptor({
      url: '/api/v1/health',
      method: 'GET',
      headers: {},
    })
    expect(request.headers.Accept).toBe('application/json')
    expect(fetch).not.toHaveBeenCalled()
  })

  it('prepares CSRF and decodes the token for session mutations', async () => {
    fetch.mockClear()
    document.cookie = 'XSRF-TOKEN=example%3D; path=/'
    const request = await options.requestInterceptor({
      url: '/api/v1/login',
      method: 'POST',
      headers: {},
    })
    expect(fetch).toHaveBeenCalledWith('/sanctum/csrf-cookie', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
    expect(request.headers['X-XSRF-TOKEN']).toBe('example=')
  })

  it('rejects external destinations before any CSRF request', async () => {
    fetch.mockClear()
    await expect(
      options.requestInterceptor({
        url: 'https://example.com/api',
        method: 'POST',
        headers: {},
      }),
    ).rejects.toThrow('origen local')
    expect(fetch).not.toHaveBeenCalled()
  })
})
