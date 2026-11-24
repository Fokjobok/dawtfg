import { apiRequest } from './api.js'
import { prepareCsrf } from './auth.js'

export function getEnrollments(courseIds = []) {
  const params = new URLSearchParams({ per_page: '100' })

  for (const id of courseIds) {
    params.append('filter[course_ids][]', String(id))
  }

  return apiRequest(`/enrollments?${params}`)
}

export async function enrollInCourse(courseId) {
  await prepareCsrf()

  const response = await apiRequest(
    `/courses/${encodeURIComponent(courseId)}/enrollments`,
    { method: 'POST' },
  )

  return response.data
}

export async function withdrawEnrollment(enrollmentId) {
  await prepareCsrf()

  return apiRequest(`/enrollments/${encodeURIComponent(enrollmentId)}`, {
    method: 'DELETE',
  })
}
