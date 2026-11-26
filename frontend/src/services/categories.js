import { apiRequest } from './api.js'
import { prepareCsrf } from './auth.js'

async function getAll(path) {
  const items = []
  let page = 1

  while (true) {
    const response = await apiRequest(`${path}?per_page=100&page=${page}`)
    items.push(...response.data)

    if (page >= response.meta.last_page) return items

    page += 1
  }
}

export async function getCategoryOptions() {
  const [categories, specialties] = await Promise.all([
    getAll('/categories'),
    getAll('/specialties'),
  ])

  return { categories, specialties }
}

export async function getCatalogOptions() {
  const [categories, specialties] = await Promise.all([
    getAll('/catalog/categories'),
    getAll('/catalog/specialties'),
  ])

  return { categories, specialties }
}

export async function createCategory(name) {
  await prepareCsrf()

  const response = await apiRequest('/categories', {
    method: 'POST',
    body: { name },
  })

  return response.data
}

export async function classifySpecialty(specialtyId, categoryId) {
  await prepareCsrf()

  const response = await apiRequest(`/specialties/${specialtyId}`, {
    method: 'PATCH',
    body: { category_id: categoryId },
  })

  return response.data
}
