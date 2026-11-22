import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { Link, MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import ProfilePage from './ProfilePage.jsx'
import { getPublicProfile, updateOwnProfile } from '../services/profiles.js'
import { AuthProvider } from '../context/AuthContext.jsx'
import { getSession } from '../services/auth.js'

vi.mock('../services/auth.js', () => ({ getSession: vi.fn() }))

vi.mock('../services/profiles.js', () => ({
  getPublicProfile: vi.fn(),
  updateOwnProfile: vi.fn(),
}))

function profile(overrides = {}) {
  return {
    id: 6,
    name: 'Public student',
    level: 7,
    avatar_url: '/api/v1/profiles/6/avatar?v=example',
    is_private: false,
    can_edit: false,
    ...overrides,
  }
}

function openProfile() {
  return render(
    <MemoryRouter initialEntries={['/profiles/6']}>
      <AuthProvider>
        <Link to="/profiles/8">Otro perfil</Link>
        <Routes>
          <Route path="profiles/:userId" element={<ProfilePage />} />
        </Routes>
      </AuthProvider>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.resetAllMocks()
  getSession.mockResolvedValue(null)
})
afterEach(() => cleanup())

it('announces loading while the profile is pending', () => {
  getPublicProfile.mockReturnValue(new Promise(() => {}))
  openProfile()
  expect(screen.getByRole('status').textContent).toBe('Cargando perfil…')
  expect(getPublicProfile).toHaveBeenCalledWith('6')
})

it('shows the public avatar, name, level and permitted biography', async () => {
  getPublicProfile.mockResolvedValue(profile({ bio: 'Public biography.' }))
  openProfile()
  await screen.findByRole('heading', { name: 'Public student' })
  expect(screen.getByText('Nivel 7')).toBeTruthy()
  const avatar = screen.getByRole('img', { name: 'Avatar de Public student' })
  expect(avatar.getAttribute('src')).toBe('/api/v1/profiles/6/avatar?v=example')
  expect(avatar.getAttribute('width')).toBe('128')
  expect(avatar.getAttribute('height')).toBe('128')
  expect(screen.getByText('Public biography.')).toBeTruthy()
  expect(screen.queryByText(/Tu perfil es/)).toBeNull()
  expect(screen.queryByRole('button', { name: 'Guardar cambios' })).toBeNull()
})

it('keeps the basic identity visible on a private profile without its biography', async () => {
  getPublicProfile.mockResolvedValue(profile({ is_private: true }))
  openProfile()
  await screen.findByRole('heading', { name: 'Public student' })
  expect(screen.getByText('Nivel 7')).toBeTruthy()
  expect(
    screen.getByRole('img', { name: 'Avatar de Public student' }),
  ).toBeTruthy()
  expect(screen.queryByRole('heading', { name: 'Biografía' })).toBeNull()
  expect(screen.queryByText(/Tu perfil es/)).toBeNull()
})

it('does not invent an empty biography when that section is omitted on a public profile', async () => {
  getPublicProfile.mockResolvedValue(profile())
  openProfile()
  await screen.findByRole('heading', { name: 'Public student' })
  expect(screen.queryByRole('heading', { name: 'Biografía' })).toBeNull()
  expect(screen.queryByText('Todavía no hay una biografía.')).toBeNull()
})

it('lets owners see their private biography and the global privacy status', async () => {
  getPublicProfile.mockResolvedValue(
    profile({
      is_private: true,
      can_edit: true,
      bio_is_private: true,
      bio: 'Owner-only biography.',
    }),
  )
  openProfile()
  await screen.findByText('Owner-only biography.', { selector: 'p' })
  expect(screen.getByText('Tu perfil es privado.')).toBeTruthy()
  expect(screen.getByRole('heading', { name: 'Biografía' })).toBeTruthy()
})

it('distinguishes a permitted empty biography and absent avatar from hidden content', async () => {
  getPublicProfile.mockResolvedValue(profile({ avatar_url: null, bio: null }))
  openProfile()
  await screen.findByText('Todavía no hay una biografía.')
  expect(screen.getByText('Sin avatar.')).toBeTruthy()
  expect(screen.queryByRole('img')).toBeNull()
})

it.each([
  [404, 'Este perfil no existe.'],
  [500, 'No se pudo cargar el perfil. Comprueba la conexión.'],
])('shows the appropriate error for status %s', async (status, message) => {
  getPublicProfile.mockRejectedValue({ status })
  openProfile()
  expect((await screen.findByRole('alert')).textContent).toBe(message)
  expect(screen.queryByText('Nivel 7')).toBeNull()
})

it('ignores a late response from a different profile after navigation', async () => {
  let resolvePrevious
  getPublicProfile
    .mockReturnValueOnce(
      new Promise((resolve) => {
        resolvePrevious = resolve
      }),
    )
    .mockResolvedValueOnce(
      profile({ id: 8, name: 'Next student', is_private: true }),
    )
  openProfile()
  fireEvent.click(screen.getByRole('link', { name: 'Otro perfil' }))
  await screen.findByRole('heading', { name: 'Next student' })
  await act(async () =>
    resolvePrevious(profile({ bio: 'Previous biography.' })),
  )
  expect(getPublicProfile.mock.calls).toEqual([['6'], ['8']])
  expect(screen.queryByRole('heading', { name: 'Public student' })).toBeNull()
  expect(screen.queryByText('Previous biography.')).toBeNull()
  expect(screen.getByRole('heading', { name: 'Next student' })).toBeTruthy()
})

it('updates the profile heading after the owner saves a name', async () => {
  getPublicProfile.mockResolvedValue(profile({ can_edit: true, bio: null }))
  updateOwnProfile.mockResolvedValue(
    profile({ can_edit: true, name: 'Updated student', bio: null }),
  )
  openProfile()
  const input = await screen.findByLabelText('Nombre')
  fireEvent.change(input, { target: { value: 'Updated student' } })
  fireEvent.submit(
    screen.getByRole('button', { name: 'Guardar cambios' }).closest('form'),
  )
  await screen.findByRole('heading', { name: 'Updated student' })
  expect(updateOwnProfile).toHaveBeenCalledExactlyOnceWith({
    name: 'Updated student',
    bio: null,
    is_private: false,
    bio_is_private: false,
  })
  expect(screen.getByText('Perfil actualizado correctamente.')).toBeTruthy()
})

it('keeps the current profile and entered name after a field validation error', async () => {
  getPublicProfile.mockResolvedValue(profile({ can_edit: true }))
  updateOwnProfile.mockRejectedValue({
    status: 422,
    errors: { name: ['Invalid name.'] },
  })
  openProfile()
  const input = await screen.findByLabelText('Nombre')
  fireEvent.change(input, { target: { value: 'Attempt' } })
  fireEvent.submit(
    screen.getByRole('button', { name: 'Guardar cambios' }).closest('form'),
  )
  await screen.findByText('Invalid name.')
  expect(input.value).toBe('Attempt')
  expect(input.getAttribute('aria-invalid')).toBe('true')
  expect(screen.getByRole('heading', { name: 'Public student' })).toBeTruthy()
  expect(screen.getByRole('button', { name: 'Guardar cambios' }).disabled).toBe(
    false,
  )
})

it('retains the section preference when switching global privacy and saves boolean settings', async () => {
  const own = profile({
    can_edit: true,
    bio: 'Biography.',
    bio_is_private: true,
  })
  getPublicProfile.mockResolvedValue(own)
  updateOwnProfile.mockResolvedValue({ ...own, is_private: true })
  openProfile()
  const visibility = await screen.findByLabelText('Visibilidad del perfil')
  const section = screen.getByLabelText('Visibilidad de la biografía')
  expect(section.value).toBe('private')
  expect(section.disabled).toBe(false)
  fireEvent.change(visibility, { target: { value: 'private' } })
  expect(section.disabled).toBe(true)
  expect(section.value).toBe('private')
  fireEvent.change(visibility, { target: { value: 'public' } })
  expect(section.disabled).toBe(false)
  expect(section.value).toBe('private')
  fireEvent.change(visibility, { target: { value: 'private' } })
  fireEvent.submit(
    screen.getByRole('button', { name: 'Guardar cambios' }).closest('form'),
  )
  await screen.findByText('Perfil actualizado correctamente.')
  expect(updateOwnProfile).toHaveBeenCalledExactlyOnceWith({
    name: own.name,
    bio: 'Biography.',
    is_private: true,
    bio_is_private: true,
  })
  expect(screen.getByText('Tu perfil es privado.')).toBeTruthy()
  expect(screen.getByRole('textbox', { name: 'Biografía' }).value).toBe(
    'Biography.',
  )
})

it('saves an edited biography with independent section privacy and updates the visible profile', async () => {
  const own = profile({
    can_edit: true,
    bio: 'Previous biography.',
    bio_is_private: false,
  })
  getPublicProfile.mockResolvedValue(own)
  updateOwnProfile.mockResolvedValue({
    ...own,
    bio: 'Updated biography.',
    bio_is_private: true,
  })
  openProfile()
  const biography = await screen.findByRole('textbox', { name: 'Biografía' })
  fireEvent.change(biography, { target: { value: 'Updated biography.' } })
  fireEvent.change(screen.getByLabelText('Visibilidad de la biografía'), {
    target: { value: 'private' },
  })
  fireEvent.submit(
    screen.getByRole('button', { name: 'Guardar cambios' }).closest('form'),
  )
  await screen.findByText('Perfil actualizado correctamente.')
  expect(updateOwnProfile).toHaveBeenCalledExactlyOnceWith({
    name: own.name,
    bio: 'Updated biography.',
    is_private: false,
    bio_is_private: true,
  })
  expect(screen.getByText('Updated biography.', { selector: 'p' })).toBeTruthy()
  expect(screen.getByLabelText('Visibilidad de la biografía').value).toBe(
    'private',
  )
})
