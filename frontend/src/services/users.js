import { apiRequest } from './api.js'
import { prepareCsrf } from './auth.js'

export function getUsers(page = 1, role = '') {
  const params = new URLSearchParams({
    page: String(page),
  })

  if (role) {
    params.set('filter[role]', role)
  }

  return apiRequest(`/users?${params.toString()}`)
}

export async function updateUser(id, changes) {
  await prepareCsrf()

  const response = await apiRequest(`/users/${id}`, {
    method: 'PATCH',
    body: changes,
  })

  return response.data
}
