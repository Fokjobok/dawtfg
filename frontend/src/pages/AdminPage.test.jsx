import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import AdminPage from './AdminPage.jsx'
import EditUserForm from '../components/EditUserForm.jsx'
import ToastProvider from '../components/ToastProvider.jsx'
import { getMedalOptions } from '../services/medals.js'
import { getSession } from '../services/auth.js'
import { getUsers, updateUser } from '../services/users.js'

vi.mock('../services/auth.js', () => ({ getSession: vi.fn() }))
vi.mock('../services/users.js', () => ({
  getUsers: vi.fn(),
  updateUser: vi.fn(),
}))
vi.mock('../services/medals.js', () => ({ getMedalOptions: vi.fn() }))

const admin = { id: 1, name: 'Admin', role: 'Administrator' }
const coach = {
  id: 2,
  name: 'Coach Example',
  email: 'coach@example.test',
  role: 'Coach',
}
const student = {
  id: 3,
  name: 'Student Example',
  email: 'student@example.test',
  role: 'Student',
}

beforeEach(() => {
  vi.resetAllMocks()
  getSession.mockResolvedValue(admin)
  getUsers.mockResolvedValue({ data: [coach] })
  getMedalOptions.mockResolvedValue({ medals: [], specialties: [], users: [] })
})

afterEach(cleanup)

function openPanel() {
  render(
    <MemoryRouter>
      <ToastProvider>
        <AdminPage />
      </ToastProvider>
    </MemoryRouter>,
  )
}

it('waits for a role selection before requesting users and clears the list on reset', async () => {
  openPanel()
  const filter = await screen.findByLabelText('Tipo de usuario')
  expect(getUsers).not.toHaveBeenCalled()
  fireEvent.change(filter, { target: { value: 'Coach' } })
  await screen.findByText(coach.name)
  expect(getUsers).toHaveBeenCalledWith(1, 'Coach')
  fireEvent.change(filter, { target: { value: '' } })
  expect(screen.queryByText(coach.name)).toBeNull()
  expect(
    screen.getByText(
      'Selecciona un tipo de usuario para consultar el listado.',
    ),
  ).toBeTruthy()
})

it.each(['Student', 'Coach'])(
  'does not load administrative data for %s',
  async (role) => {
    getSession.mockResolvedValue({ ...admin, role })
    openPanel()
    await screen.findByText('No tienes permisos para acceder a administración.')
    expect(getUsers).not.toHaveBeenCalled()
  },
)

it('offers login to anonymous visitors without requesting the list', async () => {
  getSession.mockResolvedValue(null)
  openPanel()
  await screen.findByRole('link', { name: 'Inicia sesión para continuar' })
  expect(getUsers).not.toHaveBeenCalled()
})

it('ignores an old role response when the selection changes', async () => {
  let resolveCoaches
  getUsers.mockImplementation((page, role) =>
    role === 'Coach'
      ? new Promise((resolve) => {
          resolveCoaches = resolve
        })
      : Promise.resolve({ data: [student] }),
  )
  openPanel()
  const filter = await screen.findByLabelText('Tipo de usuario')
  fireEvent.change(filter, { target: { value: 'Coach' } })
  fireEvent.change(filter, { target: { value: 'Student' } })
  await screen.findByText(student.name)
  await act(async () => {
    resolveCoaches({ data: [coach] })
  })
  expect(screen.queryByText(coach.name)).toBeNull()
  expect(screen.getByText(student.name)).toBeTruthy()
})

it('saves edits and removes a user from the old role list after a role change', async () => {
  openPanel()
  fireEvent.change(await screen.findByLabelText('Tipo de usuario'), {
    target: { value: 'Coach' },
  })
  await screen.findByText(coach.name)
  fireEvent.click(screen.getByRole('button', { name: 'Editar' }))
  fireEvent.change(screen.getByLabelText('Nombre'), {
    target: { value: 'Updated Coach' },
  })
  updateUser.mockResolvedValueOnce({ ...coach, name: 'Updated Coach' })
  fireEvent.submit(
    screen.getByRole('button', { name: 'Guardar cambios' }).closest('form'),
  )
  await waitFor(() =>
    expect(
      screen.queryByRole('heading', { name: 'Editar usuario' }),
    ).toBeNull(),
  )
  expect(updateUser).toHaveBeenCalledWith(coach.id, {
    name: 'Updated Coach',
    email: coach.email,
    role: 'Coach',
    specialty_ids: [],
  })
  expect(screen.getByText('Updated Coach')).toBeTruthy()

  fireEvent.click(screen.getByRole('button', { name: 'Editar' }))
  fireEvent.change(screen.getByLabelText('Rol'), {
    target: { value: 'Student' },
  })
  updateUser.mockResolvedValueOnce({
    ...coach,
    name: 'Updated Coach',
    role: 'Student',
  })
  fireEvent.submit(
    screen.getByRole('button', { name: 'Guardar cambios' }).closest('form'),
  )
  await screen.findByText('No hay usuarios de este tipo para mostrar.')
  expect(screen.queryByRole('heading', { name: 'Editar usuario' })).toBeNull()
})

it('preserves entered values and displays field errors when an edit is rejected', async () => {
  const onSaved = vi.fn()
  updateUser.mockRejectedValue({
    status: 422,
    errors: { email: ['Correo ocupado'] },
  })
  render(<EditUserForm account={coach} onSaved={onSaved} onCancel={vi.fn()} />)
  fireEvent.change(screen.getByLabelText('Correo electrónico'), {
    target: { value: 'taken@example.test' },
  })
  fireEvent.submit(
    screen.getByRole('button', { name: 'Guardar cambios' }).closest('form'),
  )
  await screen.findByText('Correo ocupado')
  expect(screen.getByLabelText('Correo electrónico').value).toBe(
    'taken@example.test',
  )
  expect(
    screen.getByLabelText('Correo electrónico').getAttribute('aria-invalid'),
  ).toBe('true')
  expect(onSaved).not.toHaveBeenCalled()
  expect(screen.getByRole('button', { name: 'Guardar cambios' }).disabled).toBe(
    false,
  )
})

it('cancels editing without submitting an update', () => {
  const onCancel = vi.fn()
  render(<EditUserForm account={coach} onSaved={vi.fn()} onCancel={onCancel} />)
  fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }))
  expect(onCancel).toHaveBeenCalledOnce()
  expect(updateUser).not.toHaveBeenCalled()
})
