import { apiRequest } from './api.js'
import { prepareCsrf } from './auth.js'

export async function getPublicProfile(userId) {
  const response = await apiRequest(`/profiles/${encodeURIComponent(userId)}`)

  return response.data
}

export async function updateOwnProfile(details) {
  await prepareCsrf()

  const response = await apiRequest('/profile', {
    method: 'PATCH',
    body: details,
  })

  return response.data
}
