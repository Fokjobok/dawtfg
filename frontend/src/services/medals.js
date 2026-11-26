import { apiRequest } from './api.js'
import { prepareCsrf } from './auth.js'

async function getAll(path) {
  const items = []
  let page = 1

  while (true) {
    const response = await apiRequest(`${path}?per_page=100&page=${page}`)

    items.push(...response.data)

    if (page >= response.meta.last_page) {
      return items
    }

    page += 1
  }
}

export async function getMedalOptions() {
  const [specialties, users] = await Promise.all([
    getAll('/specialties'),
    getAll('/medal-recipients'),
  ])

  return { specialties, users }
}

export function getUserMedals(userId) {
  return getAll(`/users/${userId}/medals`)
}

export async function createMedal({ name, specialty_id, image }) {
  let body = { name, specialty_id }

  if (image) {
    body = new FormData()
    body.append('name', name)
    body.append('specialty_id', specialty_id)
    body.append('image', image)
  }

  await prepareCsrf()

  const response = await apiRequest('/medals', {
    method: 'POST',
    body,
  })

  return response.data
}

export async function assignMedal(userId, medalId) {
  await prepareCsrf()

  await apiRequest(`/users/${userId}/medals/${medalId}`, {
    method: 'PUT',
  })
}

export async function revokeMedal(userId, medalId) {
  await prepareCsrf()

  await apiRequest(`/users/${userId}/medals/${medalId}`, {
    method: 'DELETE',
  })
}

export function getMedals(specialtyId, page = 1) {
  const params = new URLSearchParams({
    'filter[specialty_id]': String(specialtyId),
    page: String(page),
    per_page: '20',
  })

  return apiRequest(`/medals?${params}`)
}
