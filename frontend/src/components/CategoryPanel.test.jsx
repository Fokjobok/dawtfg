import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import CategoryPanel from './CategoryPanel.jsx'
import ToastProvider from './ToastProvider.jsx'
import {
  classifySpecialty,
  createCategory,
  getCategoryOptions,
} from '../services/categories.js'

vi.mock('../services/categories.js', () => ({
  classifySpecialty: vi.fn(),
  createCategory: vi.fn(),
  getCategoryOptions: vi.fn(),
}))
const category = { id: 1, name: 'Videojuegos' }
const specialty = { id: 7, name: 'Counter Strike', category: null }

beforeEach(() => {
  vi.resetAllMocks()
  getCategoryOptions.mockResolvedValue({
    categories: [category],
    specialties: [specialty],
  })
})
afterEach(cleanup)

function openPanel() {
  return render(
    <ToastProvider>
      <CategoryPanel />
    </ToastProvider>,
  )
}

it('creates a category without automatically classifying a specialty', async () => {
  createCategory.mockResolvedValue({ id: 2, name: 'Asignaturas' })
  openPanel()
  fireEvent.change(await screen.findByLabelText('Nombre de la categoría'), {
    target: { value: ' Asignaturas ' },
  })
  fireEvent.click(screen.getByRole('button', { name: 'Crear categoría' }))
  await screen.findByText('Categoría creada correctamente.')
  expect(createCategory).toHaveBeenCalledExactlyOnceWith('Asignaturas')
  expect(screen.getByLabelText('Nombre de la categoría').value).toBe('')
  expect(screen.getByRole('option', { name: 'Asignaturas' })).toBeTruthy()
  expect(classifySpecialty).not.toHaveBeenCalled()
})

it('saves classification, disables unchanged submissions and allows clearing the category', async () => {
  classifySpecialty
    .mockResolvedValueOnce({ ...specialty, category })
    .mockResolvedValueOnce(specialty)
  openPanel()
  fireEvent.change(await screen.findByLabelText('Videojuego o materia'), {
    target: { value: '7' },
  })
  const save = screen.getByRole('button', { name: 'Guardar categoría' })
  expect(save.disabled).toBe(true)
  fireEvent.change(screen.getByLabelText('Categoría'), {
    target: { value: '1' },
  })
  fireEvent.click(save)
  await screen.findByText('Categoría asignada correctamente.')
  expect(classifySpecialty).toHaveBeenLastCalledWith('7', 1)
  expect(save.disabled).toBe(true)
  fireEvent.change(screen.getByLabelText('Categoría'), {
    target: { value: '' },
  })
  fireEvent.click(save)
  await waitFor(() => expect(save.disabled).toBe(true))
  expect(classifySpecialty).toHaveBeenLastCalledWith('7', null)
})

it('retries failed loading and preserves the name when validation fails', async () => {
  getCategoryOptions.mockRejectedValueOnce(new Error('Unavailable'))
  createCategory.mockRejectedValue({
    status: 422,
    errors: { name: ['Already exists.'] },
  })
  openPanel()
  fireEvent.click(await screen.findByRole('button', { name: 'Reintentar' }))
  const name = await screen.findByLabelText('Nombre de la categoría')
  fireEvent.change(name, { target: { value: 'Attempt' } })
  fireEvent.click(screen.getByRole('button', { name: 'Crear categoría' }))
  await screen.findByText('Already exists.')
  expect(name.value).toBe('Attempt')
  expect(screen.queryByText('Categoría creada correctamente.')).toBeNull()
})

it('blocks duplicate saves and both forms while a classification request is pending', async () => {
  let finish
  classifySpecialty.mockImplementation(
    () =>
      new Promise((resolve) => {
        finish = resolve
      }),
  )
  openPanel()
  fireEvent.change(await screen.findByLabelText('Videojuego o materia'), {
    target: { value: '7' },
  })
  fireEvent.change(screen.getByLabelText('Categoría'), {
    target: { value: '1' },
  })
  const save = screen.getByRole('button', { name: 'Guardar categoría' })
  fireEvent.click(save)
  fireEvent.submit(save.closest('form'))
  expect(classifySpecialty).toHaveBeenCalledTimes(1)
  expect(
    screen.getByLabelText('Nombre de la categoría').closest('fieldset')
      .disabled,
  ).toBe(true)
  await act(async () => finish({ ...specialty, category }))
  await screen.findByText('Categoría asignada correctamente.')
})
