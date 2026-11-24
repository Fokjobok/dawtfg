import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import MedalPanel from './MedalPanel.jsx'
import ToastProvider from './ToastProvider.jsx'
import {
  assignMedal,
  createMedal,
  getMedalOptions,
  getUserMedals,
  revokeMedal,
} from '../services/medals.js'

vi.mock('../services/medals.js', () => ({
  assignMedal: vi.fn(),
  createMedal: vi.fn(),
  getMedalOptions: vi.fn(),
  getUserMedals: vi.fn(),
  revokeMedal: vi.fn(),
}))

const specialty = { id: 3, name: 'Tarkov' }
const medal = { id: 7, name: 'Tactics', specialty, image_url: null }
const options = {
  medals: [medal],
  specialties: [specialty],
  users: [
    { id: 1, name: 'Administrator', role: 'Administrator' },
    { id: 2, name: 'Coach', role: 'Coach' },
    { id: 9, name: 'Student', role: 'Student' },
  ],
}

beforeEach(() => {
  vi.resetAllMocks()
  getMedalOptions.mockResolvedValue(options)
  getUserMedals.mockResolvedValue([])
  assignMedal.mockResolvedValue(undefined)
  revokeMedal.mockResolvedValue(undefined)
})

afterEach(cleanup)

function openPanel() {
  return render(
    <ToastProvider>
      <MedalPanel />
    </ToastProvider>,
  )
}

function selectMedal() {
  fireEvent.change(screen.getByLabelText('Medalla'), {
    target: { value: '7' },
  })
}

it('retries loading options after failure without mounting incomplete forms', async () => {
  getMedalOptions.mockRejectedValueOnce(new Error('Unavailable'))
  openPanel()
  fireEvent.click(await screen.findByRole('button', { name: 'Reintentar' }))
  await screen.findByLabelText('Nombre de la medalla')
  expect(getMedalOptions).toHaveBeenCalledTimes(2)
})

it.each([false, true])(
  'creates a medal with optional image=%s and makes it available for assignment',
  async (withImage) => {
    const created = { ...medal, id: 8, name: 'New medal' }
    createMedal.mockResolvedValue(created)
    openPanel()
    fireEvent.change(await screen.findByLabelText('Nombre de la medalla'), {
      target: { value: ' New medal ' },
    })
    fireEvent.change(screen.getByLabelText('Juego o materia'), {
      target: { value: '3' },
    })
    const image = withImage
      ? new File(['fixture'], 'medal.png', { type: 'image/png' })
      : null
    if (image) {
      fireEvent.change(screen.getByLabelText('Imagen opcional'), {
        target: { files: [image] },
      })
    }
    fireEvent.submit(
      screen.getByRole('button', { name: 'Crear medalla' }).closest('form'),
    )
    await screen.findByText('Medalla creada correctamente.')
    expect(createMedal).toHaveBeenCalledExactlyOnceWith({
      name: 'New medal',
      specialty_id: 3,
      image,
    })
    expect(screen.getByLabelText('Nombre de la medalla').value).toBe('')
    expect(screen.getByLabelText('Imagen opcional').files.length).toBe(0)
    expect(screen.getByLabelText('Medalla').value).toBe('8')
    expect(assignMedal).not.toHaveBeenCalled()
  },
)

it('assigns to any selected account and updates buttons after confirmed assignment and revocation', async () => {
  openPanel()
  const user = await screen.findByLabelText('Usuario')
  expect(Array.from(user.options, (item) => item.value)).toEqual([
    '',
    '1',
    '2',
    '9',
  ])
  fireEvent.change(user, { target: { value: '1' } })
  selectMedal()
  const assign = screen.getByRole('button', { name: 'Asignar' })
  const revoke = screen.getByRole('button', { name: 'Retirar' })
  await waitFor(() => expect(assign.disabled).toBe(false))
  expect(revoke.disabled).toBe(true)
  fireEvent.click(assign)
  await screen.findByText('Medalla asignada.')
  expect(assignMedal).toHaveBeenCalledExactlyOnceWith('1', '7')
  expect(assign.disabled).toBe(true)
  expect(revoke.disabled).toBe(false)
  fireEvent.click(revoke)
  await screen.findByText('Medalla retirada.')
  expect(revokeMedal).toHaveBeenCalledExactlyOnceWith('1', '7')
  expect(assign.disabled).toBe(false)
  expect(revoke.disabled).toBe(true)
})

it('ignores an old account response after selecting a different recipient', async () => {
  let resolveOld
  getUserMedals.mockImplementation((id) =>
    id === '1'
      ? new Promise((resolve) => {
          resolveOld = resolve
        })
      : Promise.resolve([medal]),
  )
  openPanel()
  const user = await screen.findByLabelText('Usuario')
  fireEvent.change(user, { target: { value: '1' } })
  selectMedal()
  fireEvent.change(user, { target: { value: '9' } })
  const revoke = screen.getByRole('button', { name: 'Retirar' })
  await waitFor(() => expect(revoke.disabled).toBe(false))
  await act(async () => resolveOld([]))
  expect(revoke.disabled).toBe(false)
  expect(screen.getByRole('button', { name: 'Asignar' }).disabled).toBe(true)
})

it('keeps entered values and field errors when creation fails', async () => {
  createMedal.mockRejectedValue({
    status: 422,
    errors: { name: ['Already exists.'] },
  })
  openPanel()
  const name = await screen.findByLabelText('Nombre de la medalla')
  fireEvent.change(name, { target: { value: 'Attempt' } })
  fireEvent.change(screen.getByLabelText('Juego o materia'), {
    target: { value: '3' },
  })
  fireEvent.submit(
    screen.getByRole('button', { name: 'Crear medalla' }).closest('form'),
  )
  await screen.findByText('Already exists.')
  expect(name.value).toBe('Attempt')
  expect(name.getAttribute('aria-invalid')).toBe('true')
  expect(screen.queryByText('Medalla creada correctamente.')).toBeNull()
})

it('blocks actions when assignments cannot be loaded and does not confirm a failed assignment', async () => {
  getUserMedals.mockRejectedValueOnce(new Error('Unavailable'))
  openPanel()
  const user = await screen.findByLabelText('Usuario')
  fireEvent.change(user, { target: { value: '1' } })
  selectMedal()
  await screen.findByText(/No se pudieron consultar las medallas/)
  expect(screen.getByRole('button', { name: 'Asignar' }).disabled).toBe(true)
  expect(screen.getByRole('button', { name: 'Retirar' }).disabled).toBe(true)
  fireEvent.change(user, { target: { value: '9' } })
  const assign = screen.getByRole('button', { name: 'Asignar' })
  await waitFor(() => expect(assign.disabled).toBe(false))
  assignMedal.mockRejectedValue({ status: 403 })
  fireEvent.click(assign)
  await screen.findByText('No se pudo completar la operación.')
  expect(screen.queryByText('Medalla asignada.')).toBeNull()
  expect(screen.getByRole('button', { name: 'Retirar' }).disabled).toBe(true)
})

it('blocks duplicate requests and changes to the recipient while an assignment is pending', async () => {
  let resolveAssignment
  assignMedal.mockImplementation(
    () => new Promise((resolve) => (resolveAssignment = resolve)),
  )
  openPanel()
  const user = await screen.findByLabelText('Usuario')
  fireEvent.change(user, { target: { value: '2' } })
  selectMedal()
  const assign = screen.getByRole('button', { name: 'Asignar' })
  await waitFor(() => expect(assign.disabled).toBe(false))
  fireEvent.click(assign)
  fireEvent.submit(assign.closest('form'))
  expect(assignMedal).toHaveBeenCalledTimes(1)
  expect(user.closest('fieldset').disabled).toBe(true)
  expect(
    screen.getByLabelText('Nombre de la medalla').closest('fieldset').disabled,
  ).toBe(true)
  await act(async () => resolveAssignment())
  await screen.findByText('Medalla asignada.')
})
