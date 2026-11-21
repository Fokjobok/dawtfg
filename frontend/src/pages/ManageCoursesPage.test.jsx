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
import ManageCoursesPage from './ManageCoursesPage.jsx'
import { deleteCourse, getManagedCourses } from '../services/courses.js'

vi.mock('../services/courses.js', () => ({
  getManagedCourses: vi.fn(),
  deleteCourse: vi.fn(),
}))

function course(id, canDelete = true) {
  return {
    id,
    title: `Course ${id}`,
    specialty: { id: 1, name: 'Programming' },
    level: null,
    status: 'draft',
    cover_url: `/api/v1/courses/${id}/cover`,
    can_delete: canDelete,
  }
}

function response(data, page = 1, lastPage = 1, total = data.length) {
  return { data, meta: { current_page: page, last_page: lastPage, total } }
}

function openPage() {
  return render(
    <MemoryRouter>
      <ManageCoursesPage />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.resetAllMocks()
  vi.spyOn(window, 'confirm').mockReturnValue(true)
})

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

it('identifies the course and thumbnail while hiding deletion from collaborators', async () => {
  getManagedCourses.mockResolvedValue(response([course(17, false)]))
  const { container } = openPage()

  await screen.findByText('Course 17')
  expect(screen.getByText('Curso #17')).toBeTruthy()
  expect(screen.getByText('Nivel: Sin clasificar')).toBeTruthy()
  expect(
    screen.getByRole('link', { name: 'Editar Course 17' }).getAttribute('href'),
  ).toBe('/courses/17/edit')
  expect(container.querySelector('img').getAttribute('src')).toBe(
    '/api/v1/courses/17/cover',
  )
  expect(screen.queryByRole('button', { name: /Borrar curso/ })).toBeNull()
})

it('does not send DELETE when the course-specific confirmation is cancelled', async () => {
  getManagedCourses.mockResolvedValue(response([course(17)]))
  window.confirm.mockReturnValue(false)
  openPage()

  fireEvent.click(
    await screen.findByRole('button', { name: 'Borrar curso #17: Course 17' }),
  )
  expect(window.confirm).toHaveBeenCalledWith(
    expect.stringContaining('#17: Course 17'),
  )
  expect(deleteCourse).not.toHaveBeenCalled()
  expect(getManagedCourses).toHaveBeenCalledTimes(1)
})

it('blocks other deletions and pagination until DELETE finishes, then reloads the page', async () => {
  getManagedCourses
    .mockResolvedValueOnce(response([course(17), course(18)], 1, 2, 13))
    .mockResolvedValueOnce(response([course(18)]))
  let finishDeleting
  deleteCourse.mockImplementation(
    () =>
      new Promise((resolve) => {
        finishDeleting = resolve
      }),
  )
  openPage()

  const target = await screen.findByRole('button', {
    name: 'Borrar curso #17: Course 17',
  })
  fireEvent.click(target)
  expect(deleteCourse).toHaveBeenCalledWith(17)
  for (const button of screen.getAllByRole('button', {
    name: /Borrar curso/,
  })) {
    expect(button.disabled).toBe(true)
  }
  expect(screen.getByRole('button', { name: 'Siguiente' }).disabled).toBe(true)
  expect(
    screen
      .getByRole('region', { name: 'Gestionar cursos' })
      .getAttribute('aria-busy'),
  ).toBe('true')
  fireEvent.click(target)
  expect(deleteCourse).toHaveBeenCalledTimes(1)

  await act(async () => {
    finishDeleting(null)
  })
  await screen.findByText('Curso #17 borrado.')
  await screen.findByText('Course 18')
  expect(screen.queryByText('Course 17')).toBeNull()
  expect(getManagedCourses.mock.calls.map(([page]) => page)).toEqual([1, 1])
})

it('returns to a valid page after deleting the last course on the last page', async () => {
  const firstPage = Array.from({ length: 12 }, (_, index) => course(index + 1))
  getManagedCourses
    .mockResolvedValueOnce(response(firstPage, 1, 2, 13))
    .mockResolvedValueOnce(response([course(13)], 2, 2, 13))
    .mockResolvedValueOnce(response([], 2, 1, 12))
    .mockResolvedValueOnce(response(firstPage, 1, 1, 12))
  deleteCourse.mockResolvedValue(null)
  openPage()

  fireEvent.click(await screen.findByRole('button', { name: 'Siguiente' }))
  fireEvent.click(
    await screen.findByRole('button', { name: 'Borrar curso #13: Course 13' }),
  )
  await screen.findByText('Curso #13 borrado.')
  await screen.findByText('Course 1')
  expect(getManagedCourses.mock.calls.map(([page]) => page)).toEqual([
    1, 2, 2, 1,
  ])
  expect(screen.queryByText('Course 13')).toBeNull()
  expect(screen.queryByRole('button', { name: 'Siguiente' })).toBeNull()
})

it('preserves the course and restores controls after an authorization failure', async () => {
  getManagedCourses.mockResolvedValue(response([course(17)]))
  deleteCourse.mockRejectedValue({ status: 403 })
  openPage()

  fireEvent.click(
    await screen.findByRole('button', { name: 'Borrar curso #17: Course 17' }),
  )
  expect((await screen.findByRole('alert')).textContent).toBe(
    'No tienes permiso para borrar este curso.',
  )
  expect(screen.getByText('Course 17')).toBeTruthy()
  expect(
    screen.getByRole('button', { name: 'Borrar curso #17: Course 17' })
      .disabled,
  ).toBe(false)
  expect(getManagedCourses).toHaveBeenCalledTimes(1)
})

it('keeps confirmed deletion feedback if reloading the list fails', async () => {
  getManagedCourses
    .mockResolvedValueOnce(response([course(17)]))
    .mockRejectedValueOnce(new Error('Read failed'))
  deleteCourse.mockResolvedValue(null)
  openPage()

  fireEvent.click(
    await screen.findByRole('button', { name: 'Borrar curso #17: Course 17' }),
  )
  await screen.findByText('Curso #17 borrado.')
  expect((await screen.findByRole('alert')).textContent).toContain(
    'No se pudo cargar el listado',
  )
  expect(screen.queryByText(/No se pudo confirmar el borrado/)).toBeNull()
  await waitFor(() => expect(getManagedCourses).toHaveBeenCalledTimes(2))
})
