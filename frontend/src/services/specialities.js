import { apiRequest } from './api.js'
import { prepareCsrf } from './auth.js'

export function getSpecialties(page = 1) {
  return apiRequest(`/specialties?page=${page}`)
}

export function getSpecialtyCoaches(specialtyId, page = 1) {
  return apiRequest(`/specialties/${specialtyId}/coaches?page=${page}`)
}

export async function createSpecialty(name) {
  await prepareCsrf()

  const response = await apiRequest('/specialties', {
    method: 'POST',
    body: { name },
  })

  return response.data
}
