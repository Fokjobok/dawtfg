import { ApiError, apiRequest } from './api.js'

export async function prepareCsrf() {
  const response = await fetch('/sanctum/csrf-cookie', {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
    cache: 'no-store',
    signal: AbortSignal.timeout(10000),
  })
  if (!response.ok)
    throw new ApiError(response.status, {
      error: 'Unable to initialize the session',
    })
}

export async function getSession() {
  try {
    return (await apiRequest('/user')).data
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) return null
    throw error
  }
}

export async function register(details) {
  await prepareCsrf()
  return (await apiRequest('/register', { method: 'POST', body: details })).data
}

export async function login(details) {
  await prepareCsrf()
  return (await apiRequest('/login', { method: 'POST', body: details })).data
}

export async function logout() {
  await prepareCsrf()
  await apiRequest('/logout', { method: 'POST' })
}
