import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import CreateCoursePage from './CreateCoursePage.jsx'
import { createCourse } from '../services/courses.js'
import {
  getSpecialties,
  getSpecialtyCoaches,
} from '../services/specialities.js'

vi.mock('../services/courses.js', () => ({ createCourse: vi.fn() }))
vi.mock('../services/specialities.js', () => ({
  getSpecialties: vi.fn(),
  getSpecialtyCoaches: vi.fn(),
}))

beforeEach(() => {
  vi.resetAllMocks()
  getSpecialties.mockResolvedValue({
    data: [{ id: 1, name: 'Programming' }],
    meta: { last_page: 1 },
  })
  getSpecialtyCoaches.mockResolvedValue({
    data: [
      { id: 2, name: 'Responsible Coach' },
      { id: 3, name: 'Additional Coach' },
    ],
    meta: { last_page: 1 },
  })
})

afterEach(cleanup)

async function fillCourse() {
  render(<CreateCoursePage />)
  fireEvent.change(screen.getByLabelText('Título'), {
    target: { value: 'JavaScript course' },
  })
  fireEvent.change(screen.getByLabelText('Descripción'), {
    target: { value: 'Functions and objects.' },
  })
  const specialty = screen.getByLabelText('Videojuego o materia')
  await waitFor(() => expect(specialty.disabled).toBe(false))
  fireEvent.change(specialty, { target: { value: '1' } })
  const owner = await screen.findByLabelText('Coach responsable')
  await waitFor(() => expect(owner.options.length).toBe(3))
  fireEvent.change(owner, { target: { value: '2' } })

  const collaborators = screen.getByLabelText('Coaches colaboradores')
  collaborators.options[0].selected = true
  fireEvent.change(collaborators)

  return screen
    .getByRole('button', { name: 'Guardar borrador' })
    .closest('form')
}

it('associates validation errors with every field and preserves entered data', async () => {
  createCourse.mockRejectedValue({
    status: 422,
    errors: {
      title: ['Invalid title'],
      description: ['Invalid description'],
      specialty_id: ['Invalid specialty'],
      coach_id: ['Invalid responsible Coach'],
      additional_coach_ids: ['Invalid collaborator list'],
      'additional_coach_ids.0': ['Invalid additional Coach'],
    },
  })
  const form = await fillCourse()
  fireEvent.submit(form)
  expect((await screen.findByRole('alert')).textContent).toBe(
    'Revisa los campos del formulario.',
  )

  for (const [label, error] of [
    ['Título', 'Invalid title'],
    ['Descripción', 'Invalid description'],
    ['Videojuego o materia', 'Invalid specialty'],
    ['Coach responsable', 'Invalid responsible Coach'],
    ['Coaches colaboradores', 'Invalid additional Coach'],
  ]) {
    const control = screen.getByLabelText(label)
    expect(control.getAttribute('aria-invalid')).toBe('true')
    expect(control.getAttribute('aria-describedby').split(' ')).toContain(
      `${control.id}-error`,
    )
    expect(
      document.getElementById(`${control.id}-error`).textContent,
    ).toContain(error)
  }

  expect(screen.getByLabelText('Título').value).toBe('JavaScript course')
  expect(screen.getByLabelText('Coach responsable').value).toBe('2')
  expect(
    screen.getByLabelText('Coaches colaboradores').selectedOptions[0].value,
  ).toBe('3')
  expect(screen.getByRole('group', { name: 'Datos del curso' }).disabled).toBe(
    false,
  )
})

it('blocks the form during submission and announces success after resetting it', async () => {
  let finishSaving
  createCourse.mockImplementation(
    () =>
      new Promise((resolve) => {
        finishSaving = resolve
      }),
  )
  const form = await fillCourse()
  fireEvent.submit(form)

  const button = await screen.findByRole('button', { name: 'Guardando…' })
  expect(button.disabled).toBe(true)
  expect(form.getAttribute('aria-busy')).toBe('true')
  expect(screen.getByRole('group', { name: 'Datos del curso' }).disabled).toBe(
    true,
  )
  fireEvent.submit(form)
  expect(createCourse).toHaveBeenCalledTimes(1)

  await act(async () => {
    finishSaving({ id: 9, title: 'JavaScript course' })
  })
  expect((await screen.findByRole('status')).textContent).toBe(
    'Curso «JavaScript course» guardado como borrador.',
  )
  expect(form.getAttribute('aria-busy')).toBe('false')
  expect(screen.getByRole('group', { name: 'Datos del curso' }).disabled).toBe(
    false,
  )
  expect(screen.getByLabelText('Título').value).toBe('')
  expect(screen.getByLabelText('Videojuego o materia').value).toBe('')
})
