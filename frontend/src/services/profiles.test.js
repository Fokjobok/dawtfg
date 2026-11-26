import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { getPublicProfile, updateOwnProfile } from './profiles.js'

beforeEach(() => vi.stubGlobal('fetch', vi.fn()))
afterEach(() => vi.unstubAllGlobals())

const saved = {
  data: {
    id: 6,
    name: 'Student',
    avatar_url: null,
    save_limits: { remaining_updates: 3, retry_after: 0, available_at: null },
  },
  meta: { updated: false },
}

it('keeps the public read unwrapped and preserves update metadata through the real HTTP service', async () => {
  fetch.mockResolvedValueOnce(Response.json({ data: saved.data }))
  expect(await getPublicProfile(6)).toEqual(saved.data)
  fetch.mockResolvedValueOnce(new Response(null, { status: 204 }))
  fetch.mockResolvedValueOnce(Response.json(saved))
  expect(
    await updateOwnProfile({ name: 'Student', bio: null, is_private: false }),
  ).toEqual(saved)
  expect(fetch.mock.calls[1][0]).toBe('/sanctum/csrf-cookie')
  const [url, request] = fetch.mock.calls[2]
  expect(url).toBe('/api/v1/profile')
  expect(request.method).toBe('PATCH')
  expect(request.credentials).toBe('same-origin')
  expect(request.headers['Content-Type']).toBe('application/json')
  expect(JSON.parse(request.body)).toEqual({
    name: 'Student',
    bio: null,
    is_private: false,
  })
})

it('sends avatars as multipart with method override and 0/1 privacy without losing metadata', async () => {
  fetch.mockResolvedValueOnce(new Response(null, { status: 204 }))
  fetch.mockResolvedValueOnce(
    Response.json({ ...saved, meta: { updated: true } }),
  )
  const avatar = new File(['test-image'], 'avatar.png', { type: 'image/png' })
  const result = await updateOwnProfile({
    name: 'Student',
    bio: null,
    is_private: false,
    bio_is_private: true,
    medals_is_private: true,
    enrollments_is_private: true,
    featured_course_ids: [7, 3],
    avatar,
  })
  const [url, request] = fetch.mock.calls[1]
  expect(url).toBe('/api/v1/profile')
  expect(request.method).toBe('POST')
  expect(request.body).toBeInstanceOf(FormData)
  expect(request.body.get('_method')).toBe('PATCH')
  expect(request.body.get('is_private')).toBe('0')
  expect(request.body.get('bio_is_private')).toBe('1')
  expect(request.body.get('medals_is_private')).toBe('1')
  expect(request.body.get('enrollments_is_private')).toBe('1')
  expect(JSON.parse(request.body.get('featured_course_ids'))).toEqual([7, 3])
  expect(request.body.get('bio')).toBe('')
  expect(request.body.get('avatar').name).toBe('avatar.png')
  expect(request.headers['Content-Type']).toBeUndefined()
  expect(result.meta.updated).toBe(true)
  expect(result.data.save_limits).toEqual(saved.data.save_limits)
})

it('exposes the authoritative retry delay from an HTTP 429 to the form', async () => {
  fetch.mockResolvedValueOnce(new Response(null, { status: 204 }))
  fetch.mockResolvedValueOnce(
    Response.json(
      {
        error: 'Too Many Requests',
        code: 429,
        retry_after: 9,
      },
      { status: 429, headers: { 'Retry-After': '9' } },
    ),
  )
  await expect(
    updateOwnProfile({ name: 'Pending name' }),
  ).rejects.toMatchObject({
    status: 429,
    retryAfter: 9,
  })
})
