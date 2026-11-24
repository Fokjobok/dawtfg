import { apiRequest } from './api.js'
import { prepareCsrf } from './auth.js'

export async function getPublicProfile(userId) {
  const response = await apiRequest(`/profiles/${encodeURIComponent(userId)}`)

  return response.data
}

export async function updateOwnProfile(details) {
  const { avatar, ...fields } = details
  let body = fields

  if (avatar) {
    body = new FormData()
    body.append('_method', 'PATCH')

    for (const [field, value] of Object.entries(fields)) {
      body.append(
        field,
        typeof value === 'boolean' ? (value ? '1' : '0') : String(value ?? ''),
      )
    }

    body.append('avatar', avatar)
  }

  await prepareCsrf()

  const response = await apiRequest('/profile', {
    method: avatar ? 'POST' : 'PATCH',
    body,
    signal: AbortSignal.timeout(30000),
  })

  return response
}
