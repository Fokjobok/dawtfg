import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { getCourse, getCourses } from './courses.js'

beforeEach(() => vi.stubGlobal('fetch', vi.fn()))
afterEach(() => vi.unstubAllGlobals())

it('sends category, specialty and level together with the requested catalog page', async () => {
  fetch.mockResolvedValueOnce(Response.json({ data: [], meta: {} }))
  await getCourses(2, { category_id: '3', specialty_id: '7', level: 'expert' })
  const url = new URL(fetch.mock.calls[0][0], 'https://example.test')
  expect(url.searchParams.get('filter[category_id]')).toBe('3')
  expect(url.searchParams.get('filter[specialty_id]')).toBe('7')
  expect(url.searchParams.get('filter[level]')).toBe('expert')
  expect(url.searchParams.get('page')).toBe('2')
})

it('reads one managed course without trying to access catalog filter variables', async () => {
  fetch.mockResolvedValueOnce(Response.json({ data: { id: 7 } }))
  expect(await getCourse(7)).toEqual({ id: 7 })
  expect(fetch.mock.calls[0][0]).toBe('/api/v1/courses/7')
})
