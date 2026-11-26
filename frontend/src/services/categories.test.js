import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import {
  classifySpecialty,
  createCategory,
  getCatalogOptions,
  getCategoryOptions,
} from './categories.js'

beforeEach(() => vi.stubGlobal('fetch', vi.fn()))
afterEach(() => vi.unstubAllGlobals())

it('loads all pages from public endpoints for catalog options and private endpoints only for administration', async () => {
  fetch.mockImplementation(async (url) => {
    const parsed = new URL(url, 'https://example.test')
    return Response.json({
      data: [{ source: parsed.pathname }],
      meta: { last_page: 2 },
    })
  })
  const catalog = await getCatalogOptions()
  expect(catalog.categories).toHaveLength(2)
  expect(catalog.specialties[1].source).toBe('/api/v1/catalog/specialties')
  expect(
    fetch.mock.calls.every(([url]) => url.startsWith('/api/v1/catalog/')),
  ).toBe(true)
  fetch.mockClear()
  const admin = await getCategoryOptions()
  expect(admin.categories[0].source).toBe('/api/v1/categories')
  expect(admin.specialties[1].source).toBe('/api/v1/specialties')
})

it('creates categories and classifies or clears a specialty with CSRF and the correct HTTP method', async () => {
  fetch.mockImplementation(async (url) =>
    url === '/sanctum/csrf-cookie'
      ? new Response(null, { status: 204 })
      : Response.json({ data: { id: 3 } }),
  )
  await createCategory('Videojuegos')
  await classifySpecialty(7, 3)
  await classifySpecialty(7, null)
  expect(
    fetch.mock.calls.filter(([url]) => url === '/sanctum/csrf-cookie'),
  ).toHaveLength(3)
  expect(fetch.mock.calls[1][1].method).toBe('POST')
  expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({
    name: 'Videojuegos',
  })
  expect(fetch.mock.calls[3][0]).toBe('/api/v1/specialties/7')
  expect(fetch.mock.calls[3][1].method).toBe('PATCH')
  expect(JSON.parse(fetch.mock.calls[5][1].body)).toEqual({ category_id: null })
})
