import { apiRequest } from './api.js'
import { prepareCsrf } from './auth.js'

export async function createCourse(details, cover = null) {
  const payload = new FormData()

  const fields = [
    'title',
    'description',
    'specialty_id',
    'coach_id',
    'price',
    'status',
  ]

  for (const field of fields) {
    if (details[field] !== undefined && details[field] !== '') {
      payload.append(field, String(details[field]))
    }
  }

  for (const id of details.additional_coach_ids ?? []) {
    payload.append('additional_coach_ids[]', String(id))
  }

  if (cover) {
    payload.append('cover', cover)
  }

  await prepareCsrf()

  const response = await apiRequest('/courses', {
    method: 'POST',
    body: payload,
    signal: AbortSignal.timeout(30000),
  })

  return response.data
}

export async function getCourses(page = 1) {
  return apiRequest(`/courses?page=${page}&per_page=12`)
}
