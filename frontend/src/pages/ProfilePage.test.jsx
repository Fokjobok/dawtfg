import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from '@testing-library/react'
import { Link, MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import ProfilePage from './ProfilePage.jsx'
import { getPublicProfile, updateOwnProfile } from '../services/profiles.js'
import { AuthProvider } from '../context/AuthContext.jsx'
import ToastProvider from '../components/ToastProvider.jsx'
import { getSession } from '../services/auth.js'
import { getEnrollmentPage } from '../services/enrollments.js'

vi.mock('../services/enrollments.js', () => ({ getEnrollmentPage: vi.fn() }))

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
    ...(overrides.can_edit
      ? {
          save_limits: {
            remaining_updates: 3,
            retry_after: 0,
            available_at: null,
          },
        }
      : {}),
    ...overrides,
  }
}

function saveResponse(data, updated = true) {
  return { data, meta: { updated } }
}

function openProfile() {
  return render(
    <MemoryRouter initialEntries={['/profiles/6']}>
      <AuthProvider>
        <ToastProvider>
          <Link to="/profiles/8">Otro perfil</Link>
          <Routes>
            <Route path="profiles/:userId" element={<ProfilePage />} />
          </Routes>
        </ToastProvider>
      </AuthProvider>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.resetAllMocks()
  getSession.mockResolvedValue(null)
  getEnrollmentPage.mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1 },
  })
})
afterEach(() => {
  cleanup()
  vi.useRealTimers()
})

it('announces loading while the profile is pending', () => {
  getPublicProfile.mockReturnValue(new Promise(() => {}))
  openProfile()
  expect(screen.getByText('Cargando perfil…').getAttribute('role')).toBe(
    'status',
  )
  expect(getPublicProfile).toHaveBeenCalledWith('6')
})

function featuredCourse(id) {
  return {
    id,
    title: `Featured course ${id}`,
    cover_url: null,
    specialty: { id: 1, name: 'Game' },
    coach: { name: 'Coach' },
  }
}

it('shows permitted featured courses to visitors even with a hidden biography without requesting private enrollments', async () => {
  getPublicProfile.mockResolvedValue(
    profile({ featured_courses: [featuredCourse(2), featuredCourse(1)] }),
  )
  openProfile()
  await screen.findByRole('heading', { name: 'Cursos destacados' })
  expect(
    screen.getAllByRole('heading', { level: 3 }).map((h) => h.textContent),
  ).toEqual(['Featured course 2', 'Featured course 1'])
  expect(getEnrollmentPage).not.toHaveBeenCalled()
  expect(screen.queryByRole('heading', { name: 'Tu privacidad' })).toBeNull()
})

it('saves selection and course privacy with the existing profile form and resets the dirty baseline', async () => {
  const course = featuredCourse(4)
  const own = profile({
    can_edit: true,
    featured_course_ids: [],
    featured_courses: [],
    enrollments_is_private: false,
  })
  getPublicProfile.mockResolvedValue(own)
  getEnrollmentPage.mockResolvedValue({
    data: [{ id: 31, course_id: 4, course }],
    meta: { last_page: 1 },
  })
  updateOwnProfile.mockResolvedValue(
    saveResponse({
      ...own,
      featured_course_ids: [4],
      featured_courses: [course],
      enrollments_is_private: true,
    }),
  )
  openProfile()
  fireEvent.click(
    await screen.findByRole('checkbox', { name: /Featured course 4/ }),
  )
  fireEvent.change(
    screen.getByLabelText('Visibilidad de los cursos destacados'),
    { target: { value: 'private' } },
  )
  const save = screen.getByRole('button', { name: 'Guardar cambios' })
  expect(save.disabled).toBe(false)
  fireEvent.click(save)
  await screen.findByText('Perfil actualizado correctamente.')
  expect(updateOwnProfile).toHaveBeenCalledExactlyOnceWith({
    name: own.name,
    bio: null,
    is_private: false,
    bio_is_private: false,
    medals_is_private: false,
    enrollments_is_private: true,
    featured_course_ids: [4],
  })
  expect(save.disabled).toBe(true)
  expect(
    screen.getByRole('heading', { name: 'Featured course 4' }),
  ).toBeTruthy()
})

it('preserves the entered selection and focuses the group when a featured ID fails validation', async () => {
  getPublicProfile.mockResolvedValue(
    profile({ can_edit: true, featured_course_ids: [4] }),
  )
  updateOwnProfile.mockRejectedValue({
    status: 422,
    errors: { 'featured_course_ids.0': ['Choose your own courses.'] },
  })
  openProfile()
  fireEvent.change(await screen.findByLabelText('Nombre'), {
    target: { value: 'Pending name' },
  })
  fireEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }))
  await screen.findByText('Choose your own courses.')
  const group = screen.getByRole('group', { name: 'Mis cursos y destacados' })
  await waitFor(() => expect(document.activeElement).toBe(group))
  expect(
    screen.getByRole('button', { name: 'Quitar curso 4 de destacados' }),
  ).toBeTruthy()
})

it('keeps permitted medal groups inside the profile card even when the biography is hidden', async () => {
  getPublicProfile.mockResolvedValue(
    profile({
      medals: [
        {
          id: 1,
          name: 'Tactics',
          specialty: { id: 2, name: 'Counter Strike' },
          image_url: null,
        },
      ],
    }),
  )
  openProfile()
  const medals = await screen.findByRole('region', { name: 'Medallas' })
  const card = screen
    .getByRole('heading', { name: 'Public student' })
    .closest('header').parentElement
  expect(card.contains(medals)).toBe(true)
  expect(screen.getByText('Tactics')).toBeTruthy()
  expect(screen.queryByRole('heading', { name: 'Biografía' })).toBeNull()
})

it('omits the medal section when the API hides it but preserves the owner view of private medals', async () => {
  getPublicProfile.mockResolvedValueOnce(profile({ is_private: true }))
  const { unmount } = openProfile()
  await screen.findByRole('heading', { name: 'Public student' })
  expect(screen.queryByRole('heading', { name: 'Medallas' })).toBeNull()
  unmount()
  getPublicProfile.mockResolvedValueOnce(
    profile({
      can_edit: true,
      is_private: true,
      medals_is_private: true,
      medals: [
        {
          id: 1,
          name: 'Private medal',
          specialty: { id: 2, name: 'Counter Strike' },
          image_url: null,
        },
      ],
    }),
  )
  openProfile()
  await screen.findByRole('heading', { name: 'Medallas' })
  expect(screen.getByText('Private medal')).toBeTruthy()
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
  expect(screen.queryByText('Sin avatar.')).toBeNull()
  expect(screen.queryByRole('img')).toBeNull()
  expect(
    screen
      .getByRole('heading', { name: 'Public student' })
      .closest('header')
      .querySelector('[aria-hidden="true"]'),
  ).toBeNull()
})

it.each([
  [404, 'Este perfil no existe.'],
  [500, 'No se pudo cargar el perfil. Comprueba la conexión.'],
])('shows the appropriate error for status %s', async (status, message) => {
  getPublicProfile.mockRejectedValue({ status })
  openProfile()
  expect((await screen.findByText(message)).getAttribute('role')).toBe('alert')
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
    saveResponse(
      profile({ can_edit: true, name: 'Updated student', bio: null }),
    ),
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
    medals_is_private: false,
    enrollments_is_private: false,
    featured_course_ids: [],
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
  updateOwnProfile.mockResolvedValue(saveResponse({ ...own, is_private: true }))
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
    medals_is_private: false,
    enrollments_is_private: false,
    featured_course_ids: [],
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
  updateOwnProfile.mockResolvedValue(
    saveResponse({
      ...own,
      bio: 'Updated biography.',
      bio_is_private: true,
    }),
  )
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
    medals_is_private: false,
    enrollments_is_private: false,
    featured_course_ids: [],
  })
  expect(screen.getByText('Updated biography.', { selector: 'p' })).toBeTruthy()
  expect(screen.getByLabelText('Visibilidad de la biografía').value).toBe(
    'private',
  )
})

it('updates the displayed avatar and clears the file selection after a successful upload', async () => {
  const own = profile({ can_edit: true, bio: null })
  getPublicProfile.mockResolvedValue(own)
  updateOwnProfile.mockResolvedValue(
    saveResponse({
      ...own,
      avatar_url: '/api/v1/profiles/6/avatar?v=replacement',
    }),
  )
  openProfile()
  const file = new File(['test-only-image'], 'avatar.png', {
    type: 'image/png',
  })
  const input = await screen.findByLabelText('Cambiar avatar')
  fireEvent.change(input, { target: { files: [file] } })
  fireEvent.submit(
    screen.getByRole('button', { name: 'Guardar cambios' }).closest('form'),
  )
  await screen.findByText('Perfil actualizado correctamente.')
  expect(updateOwnProfile).toHaveBeenCalledExactlyOnceWith({
    name: own.name,
    bio: null,
    is_private: false,
    bio_is_private: false,
    avatar: file,
    medals_is_private: false,
    enrollments_is_private: false,
    featured_course_ids: [],
  })
  expect(
    screen
      .getByRole('img', { name: 'Avatar de Public student' })
      .getAttribute('src'),
  ).toBe('/api/v1/profiles/6/avatar?v=replacement')
  expect(screen.getByLabelText('Cambiar avatar').files.length).toBe(0)
})

it('discards a selected replacement when the owner chooses avatar removal', async () => {
  const own = profile({ can_edit: true, bio: null })
  getPublicProfile.mockResolvedValue(own)
  updateOwnProfile.mockResolvedValue(saveResponse({ ...own, avatar_url: null }))
  openProfile()
  const input = await screen.findByLabelText('Cambiar avatar')
  fireEvent.change(input, {
    target: {
      files: [new File(['image'], 'avatar.png', { type: 'image/png' })],
    },
  })
  fireEvent.click(screen.getByLabelText('Eliminar mi avatar actual al guardar'))
  expect(screen.getByLabelText('Cambiar avatar').files.length).toBe(0)
  fireEvent.submit(
    screen.getByRole('button', { name: 'Guardar cambios' }).closest('form'),
  )
  await screen.findByText('Perfil actualizado correctamente.')
  expect(
    screen.queryByRole('img', { name: 'Avatar de Public student' }),
  ).toBeNull()
  expect(screen.queryByText('Sin avatar.')).toBeNull()
  expect(updateOwnProfile).toHaveBeenCalledExactlyOnceWith({
    name: own.name,
    bio: null,
    is_private: false,
    bio_is_private: false,
    remove_avatar: true,
    medals_is_private: false,
    enrollments_is_private: false,
    featured_course_ids: [],
  })
  expect(
    screen.queryByLabelText('Eliminar mi avatar actual al guardar'),
  ).toBeNull()
})

it('keeps the selected file and entered data after an avatar validation error', async () => {
  getPublicProfile.mockResolvedValue(profile({ can_edit: true }))
  updateOwnProfile.mockRejectedValue({
    status: 422,
    errors: { avatar: ['The avatar is too large.'] },
  })
  openProfile()
  const input = await screen.findByLabelText('Cambiar avatar')
  const file = new File(['image'], 'avatar.png', { type: 'image/png' })
  fireEvent.change(input, { target: { files: [file] } })
  fireEvent.change(screen.getByLabelText('Nombre'), {
    target: { value: 'Pending name' },
  })
  fireEvent.submit(
    screen.getByRole('button', { name: 'Guardar cambios' }).closest('form'),
  )
  await screen.findByText('The avatar is too large.')
  expect(input.getAttribute('aria-invalid')).toBe('true')
  expect(input.getAttribute('aria-describedby')).toContain(
    'profile-avatar-error',
  )
  expect(input.files[0]).toBe(file)
  expect(screen.getByLabelText('Nombre').value).toBe('Pending name')
  expect(screen.getByRole('heading', { name: 'Public student' })).toBeTruthy()
})

it('blocks unchanged submissions and disables saving again when all edits are reverted', async () => {
  getPublicProfile.mockResolvedValue(
    profile({ can_edit: true, bio: 'Saved biography.', bio_is_private: false }),
  )
  openProfile()
  const name = await screen.findByLabelText('Nombre')
  const button = screen.getByRole('button', { name: 'Guardar cambios' })
  const form = button.closest('form')
  expect(button.disabled).toBe(true)
  fireEvent.submit(form)

  for (const [control, changed, original] of [
    [name, 'Edited name', 'Public student'],
    [
      screen.getByRole('textbox', { name: 'Biografía' }),
      'Edited biography.',
      'Saved biography.',
    ],
    [screen.getByLabelText('Visibilidad del perfil'), 'private', 'public'],
    [screen.getByLabelText('Visibilidad de la biografía'), 'private', 'public'],
    [screen.getByLabelText('Visibilidad de las medallas'), 'private', 'public'],
  ]) {
    fireEvent.change(control, { target: { value: changed } })
    expect(button.disabled).toBe(false)
    fireEvent.change(control, { target: { value: original } })
    expect(button.disabled).toBe(true)
    fireEvent.submit(form)
  }

  fireEvent.change(name, { target: { value: ' Public student ' } })
  expect(button.disabled).toBe(true)
  expect(updateOwnProfile).not.toHaveBeenCalled()
  expect(screen.queryByText('Perfil actualizado correctamente.')).toBeNull()
})

it('restores a server cooldown on load while preserving edits until it expires', async () => {
  vi.useFakeTimers()
  getPublicProfile.mockResolvedValue(
    profile({
      can_edit: true,
      save_limits: {
        remaining_updates: 0,
        retry_after: 20,
        available_at: new Date(Date.now() + 20000).toISOString(),
      },
    }),
  )
  await act(async () => {
    openProfile()
  })
  const name = screen.getByLabelText('Nombre')
  const button = screen.getByRole('button', { name: 'Guardar cambios' })
  expect(screen.getByText('Podrás guardar de nuevo en 20 s.')).toBeTruthy()
  expect(name.closest('fieldset').disabled).toBe(false)
  fireEvent.change(name, { target: { value: 'Pending name' } })
  expect(button.disabled).toBe(true)
  fireEvent.submit(button.closest('form'))
  expect(updateOwnProfile).not.toHaveBeenCalled()
  await act(async () => {
    vi.advanceTimersByTime(19000)
  })
  expect(screen.getByText('Podrás guardar de nuevo en 1 s.')).toBeTruthy()
  await act(async () => {
    vi.advanceTimersByTime(1000)
  })
  expect(button.disabled).toBe(false)
  expect(name.value).toBe('Pending name')
  expect(updateOwnProfile).not.toHaveBeenCalled()
  fireEvent.change(name, { target: { value: 'Public student' } })
  expect(button.disabled).toBe(true)
})

it('starts the cooldown after the third confirmed save and enables only pending changes afterward', async () => {
  vi.useFakeTimers()
  const own = profile({ can_edit: true })
  getPublicProfile.mockResolvedValue(own)
  for (const save of [1, 2, 3]) {
    updateOwnProfile.mockResolvedValueOnce(
      saveResponse({
        ...own,
        name: `Saved ${save}`,
        save_limits: {
          remaining_updates: 3 - save,
          retry_after: save === 3 ? 60 : 0,
          available_at:
            save === 3 ? new Date(Date.now() + 60000).toISOString() : null,
        },
      }),
    )
  }
  await act(async () => {
    openProfile()
  })
  const name = screen.getByLabelText('Nombre')
  const button = screen.getByRole('button', { name: 'Guardar cambios' })
  for (const save of [1, 2, 3]) {
    fireEvent.change(name, { target: { value: `Saved ${save}` } })
    expect(button.disabled).toBe(false)
    await act(async () => {
      fireEvent.submit(button.closest('form'))
    })
    expect(updateOwnProfile).toHaveBeenCalledTimes(save)
    expect(button.disabled).toBe(true)
  }
  expect(screen.getByText('Podrás guardar de nuevo en 60 s.')).toBeTruthy()
  fireEvent.change(name, { target: { value: 'Pending fourth save' } })
  expect(name.closest('fieldset').disabled).toBe(false)
  fireEvent.submit(button.closest('form'))
  expect(updateOwnProfile).toHaveBeenCalledTimes(3)
  await act(async () => {
    vi.advanceTimersByTime(60000)
  })
  expect(button.disabled).toBe(false)
  expect(name.value).toBe('Pending fourth save')
  fireEvent.change(name, { target: { value: 'Saved 3' } })
  expect(button.disabled).toBe(true)
})

it('honors a 429 received from another session and keeps pending data during the wait', async () => {
  vi.useFakeTimers()
  getPublicProfile.mockResolvedValue(profile({ can_edit: true }))
  updateOwnProfile.mockRejectedValue({ status: 429, retryAfter: 7 })
  await act(async () => {
    openProfile()
  })
  const name = screen.getByLabelText('Nombre')
  fireEvent.change(name, { target: { value: 'Unsaved name' } })
  const button = screen.getByRole('button', { name: 'Guardar cambios' })
  await act(async () => {
    fireEvent.submit(button.closest('form'))
  })
  expect(
    screen
      .getByText('Podrás guardar de nuevo al terminar la cuenta atrás.')
      .closest('[role="status"]'),
  ).not.toBeNull()
  expect(screen.getByText('Podrás guardar de nuevo en 7 s.')).toBeTruthy()
  expect(button.disabled).toBe(true)
  expect(name.value).toBe('Unsaved name')
  expect(name.closest('fieldset').disabled).toBe(false)
  fireEvent.submit(button.closest('form'))
  expect(updateOwnProfile).toHaveBeenCalledTimes(1)
  await act(async () => {
    vi.advanceTimersByTime(7000)
  })
  expect(button.disabled).toBe(false)
  expect(name.value).toBe('Unsaved name')
})

it('reports a server no-op without claiming a successful update and resets the baseline', async () => {
  const own = profile({ can_edit: true })
  getPublicProfile.mockResolvedValue(own)
  updateOwnProfile.mockResolvedValue(
    saveResponse({ ...own, name: 'Current server name' }, false),
  )
  openProfile()
  const name = await screen.findByLabelText('Nombre')
  fireEvent.change(name, { target: { value: 'Current server name' } })
  fireEvent.submit(
    screen.getByRole('button', { name: 'Guardar cambios' }).closest('form'),
  )
  await screen.findByText('Los datos ya estaban actualizados.')
  expect(screen.queryByText('Perfil actualizado correctamente.')).toBeNull()
  expect(
    screen.getByRole('heading', { name: 'Current server name' }),
  ).toBeTruthy()
  expect(screen.getByRole('button', { name: 'Guardar cambios' }).disabled).toBe(
    true,
  )
})

it('prevents duplicate requests while saving and clears pending changes after confirmation', async () => {
  let finishSave
  const own = profile({ can_edit: true })
  getPublicProfile.mockResolvedValue(own)
  updateOwnProfile.mockReturnValue(
    new Promise((resolve) => {
      finishSave = resolve
    }),
  )
  openProfile()
  const name = await screen.findByLabelText('Nombre')
  fireEvent.change(name, { target: { value: 'Saved once' } })
  const form = screen
    .getByRole('button', { name: 'Guardar cambios' })
    .closest('form')
  fireEvent.submit(form)
  expect(screen.getByRole('button', { name: 'Guardando…' }).disabled).toBe(true)
  expect(name.closest('fieldset').disabled).toBe(true)
  fireEvent.submit(form)
  expect(updateOwnProfile).toHaveBeenCalledTimes(1)
  await act(async () => {
    finishSave(saveResponse({ ...own, name: 'Saved once' }))
  })
  expect(screen.getByRole('button', { name: 'Guardar cambios' }).disabled).toBe(
    true,
  )
  expect(name.closest('fieldset').disabled).toBe(false)
})

it('preserves medal privacy under the global switch and saves the section preference independently', async () => {
  const own = profile({ can_edit: true, medals_is_private: false })
  getPublicProfile.mockResolvedValue(own)
  updateOwnProfile.mockResolvedValue(
    saveResponse({ ...own, medals_is_private: true }),
  )
  openProfile()
  const section = await screen.findByLabelText('Visibilidad de las medallas')
  const visibility = screen.getByLabelText('Visibilidad del perfil')
  fireEvent.change(section, { target: { value: 'private' } })
  fireEvent.change(visibility, { target: { value: 'private' } })
  expect(section.disabled).toBe(true)
  expect(section.value).toBe('private')
  fireEvent.change(visibility, { target: { value: 'public' } })
  expect(section.disabled).toBe(false)
  expect(section.value).toBe('private')
  fireEvent.submit(
    screen.getByRole('button', { name: 'Guardar cambios' }).closest('form'),
  )
  await screen.findByText('Perfil actualizado correctamente.')
  expect(updateOwnProfile).toHaveBeenCalledExactlyOnceWith({
    name: own.name,
    bio: null,
    is_private: false,
    bio_is_private: false,
    medals_is_private: true,
    enrollments_is_private: false,
    featured_course_ids: [],
  })
  expect(screen.getByRole('button', { name: 'Guardar cambios' }).disabled).toBe(
    true,
  )
})
