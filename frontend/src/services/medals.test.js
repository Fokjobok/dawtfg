import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import {
  assignMedal,
  createMedal,
  getMedalOptions,
  getUserMedals,
  revokeMedal,
} from './medals.js'

beforeEach(() => vi.stubGlobal('fetch', vi.fn()))
afterEach(() => vi.unstubAllGlobals())

it('loads all pages of options and account assignments without mixing their responses', async () => {
  fetch.mockImplementation(async (url) => {
    const parsed = new URL(url, 'https://example.test')
    const page = Number(parsed.searchParams.get('page'))
    return Response.json({
      data: [{ id: page, source: parsed.pathname }],
      meta: { last_page: 2 },
    })
  })
  const options = await getMedalOptions()
  expect(options.medals).toEqual([
    { id: 1, source: '/api/v1/medals' },
    { id: 2, source: '/api/v1/medals' },
  ])
  expect(options.users[1].source).toBe('/api/v1/medal-recipients')
  expect(options.specialties[1].source).toBe('/api/v1/specialties')
  const medals = await getUserMedals(9)
  expect(medals).toHaveLength(2)
  expect(medals[1].source).toBe('/api/v1/users/9/medals')
  expect(fetch).toHaveBeenCalledTimes(8)
})

it.each([false, true])(
  'creates a medal with image=%s through the session and CSRF HTTP service',
  async (withImage) => {
    fetch.mockResolvedValueOnce(new Response(null, { status: 204 }))
    fetch.mockResolvedValueOnce(
      Response.json({ data: { id: 7 } }, { status: 201 }),
    )
    const image = withImage
      ? new File(['fixture'], 'medal.png', { type: 'image/png' })
      : null
    expect(
      await createMedal({ name: 'Tactics', specialty_id: 3, image }),
    ).toEqual({ id: 7 })
    expect(fetch.mock.calls[0][0]).toBe('/sanctum/csrf-cookie')
    const [url, request] = fetch.mock.calls[1]
    expect(url).toBe('/api/v1/medals')
    expect(request.method).toBe('POST')
    expect(request.credentials).toBe('same-origin')
    if (image) {
      expect(request.body).toBeInstanceOf(FormData)
      expect(request.body.get('specialty_id')).toBe('3')
      expect(request.body.get('image').name).toBe('medal.png')
      expect(request.headers['Content-Type']).toBeUndefined()
    } else {
      expect(JSON.parse(request.body)).toEqual({
        name: 'Tactics',
        specialty_id: 3,
      })
    }
  },
)

it('uses the selected account and medal IDs for CSRF-protected PUT and DELETE requests', async () => {
  fetch.mockResolvedValue(new Response(null, { status: 204 }))
  await assignMedal(9, 7)
  await revokeMedal(9, 7)
  expect(
    fetch.mock.calls.map(([url, request]) => [url, request.method ?? 'GET']),
  ).toEqual([
    ['/sanctum/csrf-cookie', 'GET'],
    ['/api/v1/users/9/medals/7', 'PUT'],
    ['/sanctum/csrf-cookie', 'GET'],
    ['/api/v1/users/9/medals/7', 'DELETE'],
  ])
})
