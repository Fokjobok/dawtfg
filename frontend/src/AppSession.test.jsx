import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  within,
} from '@testing-library/react'
import { Link, MemoryRouter, useLocation } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import App from './App.jsx'
import { AuthProvider } from './context/AuthContext.jsx'
import { getSession, logout } from './services/auth.js'
import { getPublicProfile, updateOwnProfile } from './services/profiles.js'

vi.mock('./services/auth.js', async (importOriginal) => ({
  ...(await importOriginal()),
  getSession: vi.fn(),
  logout: vi.fn(),
}))
vi.mock('./services/profiles.js', () => ({
  getPublicProfile: vi.fn(),
  updateOwnProfile: vi.fn(),
}))

const user = {
  id: 6,
  name: 'Student',
  email: 'student@example.test',
  role: 'Student',
  avatar_url: null,
}
const basicProfile = {
  id: 6,
  name: 'Student',
  level: 7,
  avatar_url: null,
  is_private: true,
  can_edit: false,
}
const ownProfile = {
  ...basicProfile,
  can_edit: true,
  bio_is_private: true,
  bio: 'Owner-only biography.',
  save_limits: { remaining_updates: 3, retry_after: 0, available_at: null },
}

function CurrentRoute() {
  return <output aria-label="Current route">{useLocation().pathname}</output>
}

async function openApp(path) {
  await act(async () =>
    render(
      <MemoryRouter initialEntries={[path]}>
        <AuthProvider>
          <CurrentRoute />
          <Link to="/profiles/6">Open test profile</Link>
          <App />
        </AuthProvider>
      </MemoryRouter>,
    ),
  )
}

beforeEach(() => {
  vi.resetAllMocks()
  getSession.mockResolvedValue(null)
  logout.mockResolvedValue(undefined)
  vi.stubGlobal('fetch', vi.fn())
})
afterEach(cleanup)

it.each([
  ['guest', '/courses/manage', '/account'],
  ['guest', '/courses/create', '/account'],
  ['Student', '/courses/manage', '/'],
  ['Student', '/courses/create', '/'],
])(
  'routes %s from %s to %s consistently',
  async (identity, path, destination) => {
    getSession.mockResolvedValue(identity === 'guest' ? null : user)
    await openApp(path)
    expect(screen.getByLabelText('Current route').textContent).toBe(destination)
    expect(getSession).toHaveBeenCalledTimes(1)
    expect(fetch).not.toHaveBeenCalled()
    expect(screen.queryByRole('link', { name: 'Gestionar cursos' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'Crear curso' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'Administración' })).toBeNull()
  },
)

it('logs out only from the account panel and clears private data before revisiting a profile', async () => {
  getSession.mockResolvedValue(user)
  let signedOut = false
  getPublicProfile.mockImplementation(async () =>
    signedOut ? basicProfile : ownProfile,
  )
  logout.mockImplementation(async () => {
    signedOut = true
  })
  await openApp('/profiles/6')
  await screen.findByText('Owner-only biography.', { selector: 'p' })
  expect(screen.getByRole('link', { name: 'Ver mi perfil' })).toBeTruthy()
  const navigation = screen.getByRole('navigation', {
    name: 'Navegación principal',
  })
  expect(
    within(navigation).queryByRole('button', { name: 'Cerrar sesión' }),
  ).toBeNull()
  expect(screen.queryByRole('button', { name: 'Cerrar sesión' })).toBeNull()
  fireEvent.click(within(navigation).getByRole('link', { name: 'Mi cuenta' }))
  await screen.findByRole('heading', { name: 'Mi perfil' })
  fireEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }))
  await screen.findByRole('button', { name: 'Entrar' })
  expect(logout).toHaveBeenCalledTimes(1)
  expect(screen.getByLabelText('Current route').textContent).toBe('/account')
  expect(screen.queryByText('Owner-only biography.')).toBeNull()
  expect(screen.queryByRole('link', { name: 'Ver mi perfil' })).toBeNull()
  fireEvent.click(screen.getByRole('link', { name: 'Open test profile' }))
  await screen.findByRole('heading', { name: 'Student' })
  expect(screen.getByText('Nivel 7')).toBeTruthy()
  expect(screen.queryByText('Owner-only biography.')).toBeNull()
  expect(screen.queryByRole('button', { name: 'Guardar cambios' })).toBeNull()
})

it('retains the identity and reports a failed logout without claiming success', async () => {
  getSession.mockResolvedValue(user)
  logout.mockRejectedValue({ status: 500 })
  await openApp('/account')
  fireEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }))
  await screen.findByText(
    'No se pudo confirmar el cierre de sesión. Inténtalo de nuevo.',
  )
  expect(screen.getByLabelText('Current route').textContent).toBe('/account')
  expect(screen.getByText(user.email)).toBeTruthy()
  expect(screen.getByRole('link', { name: 'Ver mi perfil' })).toBeTruthy()
  expect(screen.getByRole('button', { name: 'Cerrar sesión' }).disabled).toBe(
    false,
  )
})

it('clears stale client identity when logout confirms there is no server session', async () => {
  getSession.mockResolvedValue(user)
  logout.mockRejectedValue({ status: 401 })
  await openApp('/account')
  fireEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }))
  await screen.findByRole('button', { name: 'Entrar' })
  expect(screen.queryByText('Owner-only biography.')).toBeNull()
  expect(screen.queryByRole('link', { name: 'Ver mi perfil' })).toBeNull()
  expect(screen.queryByText(user.email)).toBeNull()
})

it('blocks duplicate logout requests until the account-panel request finishes', async () => {
  let finishLogout
  getSession.mockResolvedValue(user)
  logout.mockReturnValue(
    new Promise((resolve) => {
      finishLogout = resolve
    }),
  )
  await openApp('/account')
  fireEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }))
  const button = screen.getByRole('button', { name: 'Cerrando…' })
  expect(button.disabled).toBe(true)
  fireEvent.click(button)
  expect(logout).toHaveBeenCalledTimes(1)
  await act(async () => finishLogout())
  await screen.findByRole('button', { name: 'Entrar' })
})

it('updates the shared header and account identity after saving the profile', async () => {
  getSession.mockResolvedValue(user)
  getPublicProfile.mockResolvedValue(ownProfile)
  updateOwnProfile.mockResolvedValue({
    data: { ...ownProfile, name: 'Updated student' },
    meta: { updated: true },
  })
  await openApp('/profiles/6')
  const input = await screen.findByLabelText('Nombre')
  fireEvent.change(input, { target: { value: 'Updated student' } })
  fireEvent.submit(
    screen.getByRole('button', { name: 'Guardar cambios' }).closest('form'),
  )
  await screen.findByRole('heading', { name: 'Updated student' })
  const navigation = screen.getByRole('navigation', {
    name: 'Navegación principal',
  })
  expect(
    within(navigation).getByRole('link', { name: 'Ver mi perfil' }).textContent,
  ).toBe('U')
  fireEvent.click(within(navigation).getByRole('link', { name: 'Mi cuenta' }))
  await screen.findByRole('heading', { name: 'Mi perfil' })
  expect(screen.getByText('Updated student')).toBeTruthy()
  expect(getSession).toHaveBeenCalledTimes(1)
})

it('keeps notifications during navigation and discards them when the session ends', async () => {
  getSession.mockResolvedValue(user)
  getPublicProfile.mockResolvedValue(ownProfile)
  updateOwnProfile.mockResolvedValue({
    data: { ...ownProfile, name: 'Updated student' },
    meta: { updated: true },
  })
  logout.mockResolvedValue(undefined)
  await openApp('/profiles/6')
  fireEvent.change(await screen.findByLabelText('Nombre'), {
    target: { value: 'Updated student' },
  })
  fireEvent.submit(
    screen.getByRole('button', { name: 'Guardar cambios' }).closest('form'),
  )
  await screen.findByText('Perfil actualizado correctamente.')
  fireEvent.click(
    within(
      screen.getByRole('navigation', { name: 'Navegación principal' }),
    ).getByRole('link', { name: 'Mi cuenta' }),
  )
  await screen.findByRole('heading', { name: 'Mi perfil' })
  expect(screen.getByText('Perfil actualizado correctamente.')).toBeTruthy()
  fireEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }))
  await screen.findByRole('button', { name: 'Entrar' })
  expect(screen.queryByText('Perfil actualizado correctamente.')).toBeNull()
})
