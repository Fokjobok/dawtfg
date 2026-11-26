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
    'level',
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

export async function getCourses(page = 1, filters = {}) {
  const params = new URLSearchParams({
    page: String(page),
    per_page: '12',
  })

  if (filters.level) {
    params.set('filter[level]', filters.level)
  }

  if (filters.specialty_id) {
    params.set('filter[specialty_id]', String(filters.specialty_id))
  }

  if (filters.category_id) {
    params.set('filter[category_id]', String(filters.category_id))
  }

  return apiRequest(`/courses?${params}`)
}

export async function getCourse(courseId) {
  const response = await apiRequest(`/courses/${encodeURIComponent(courseId)}`)

  return response.data
}

export async function updateCourse(courseId, details) {
  await prepareCsrf()

  const response = await apiRequest(
    `/courses/${encodeURIComponent(courseId)}`,
    {
      method: 'PATCH',
      body: details,
    },
  )

  return response.data
}

export async function getManagedCourses(page = 1) {
  return apiRequest(`/teaching/courses?page=${page}&per_page=12`)
}

export async function deleteCourse(courseId) {
  await prepareCsrf()

  return apiRequest(`/courses/${encodeURIComponent(courseId)}`, {
    method: 'DELETE',
  })
}
