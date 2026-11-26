import { useState } from 'react'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import ProfileCourses, { EnrolledCoursePicker } from './ProfileCourses.jsx'
import { getEnrollmentPage } from '../services/enrollments.js'

vi.mock('../services/enrollments.js', () => ({ getEnrollmentPage: vi.fn() }))
const course = (id) => ({
  id,
  title: `Course ${id}`,
  cover_url: null,
  specialty: { name: 'Game' },
  coach: { name: 'Coach' },
})
const response = (ids, lastPage = 1) => ({
  data: ids.map((id) => ({ id: id + 100, course_id: id, course: course(id) })),
  meta: { last_page: lastPage },
})

function Harness({ initial = [], disabled = false, errors = [] }) {
  const [selectedIds, setSelectedIds] = useState(initial)
  return (
    <EnrolledCoursePicker
      selectedIds={selectedIds}
      onChange={setSelectedIds}
      disabled={disabled}
      errors={errors}
    />
  )
}

beforeEach(() => {
  vi.resetAllMocks()
  getEnrollmentPage.mockResolvedValue(response([1, 2, 3, 4]))
})
afterEach(cleanup)

it('preserves featured order, enforces three selections and allows removal before adding another', async () => {
  render(<Harness />)
  await screen.findByRole('checkbox', { name: /Course 1/ })
  for (const id of [3, 1, 2])
    fireEvent.click(
      screen.getByRole('checkbox', { name: new RegExp(`Course ${id}`) }),
    )
  expect(screen.getByRole('checkbox', { name: /Course 4/ }).disabled).toBe(true)
  const order = screen.getByRole('list', { name: 'Orden de destacados' })
  expect([...order.querySelectorAll('li')].map((li) => li.textContent)).toEqual(
    ['1. Curso #3Quitar', '2. Curso #1Quitar', '3. Curso #2Quitar'],
  )
  fireEvent.click(
    screen.getByRole('button', { name: 'Quitar curso 1 de destacados' }),
  )
  fireEvent.click(screen.getByRole('checkbox', { name: /Course 4/ }))
  expect(screen.getByRole('checkbox', { name: /Course 1/ }).checked).toBe(false)
  expect(screen.getByRole('checkbox', { name: /Course 4/ }).checked).toBe(true)
})

it('keeps selection across pages and restores the checkbox when returning', async () => {
  getEnrollmentPage.mockImplementation((page) =>
    Promise.resolve(response(page === 1 ? [1] : [2], 2)),
  )
  render(<Harness />)
  fireEvent.click(await screen.findByRole('checkbox', { name: /Course 1/ }))
  fireEvent.click(screen.getByRole('button', { name: 'Siguiente' }))
  fireEvent.click(await screen.findByRole('checkbox', { name: /Course 2/ }))
  expect(
    screen.getByRole('button', { name: 'Quitar curso 1 de destacados' }),
  ).toBeTruthy()
  fireEvent.click(screen.getByRole('button', { name: 'Anterior' }))
  expect(
    (await screen.findByRole('checkbox', { name: /Course 1/ })).checked,
  ).toBe(true)
  expect(getEnrollmentPage).toHaveBeenLastCalledWith(1)
})

it('does not reveal unavailable course details or allow a new selection but lets owners remove a previous selection', async () => {
  getEnrollmentPage.mockResolvedValue({
    data: [
      { id: 101, course_id: 1, course: null },
      { id: 102, course_id: 2, course: null },
    ],
    meta: { last_page: 1 },
  })
  render(<Harness initial={[1]} />)
  const selected = await screen.findByRole('checkbox', { name: /Curso #1/ })
  expect(selected.disabled).toBe(false)
  expect(screen.getByRole('checkbox', { name: /Curso #2/ }).disabled).toBe(true)
  fireEvent.click(selected)
  expect(selected.checked).toBe(false)
  expect(selected.disabled).toBe(true)
})

it('offers retry, clamps an obsolete page and preserves selected IDs on load failure', async () => {
  getEnrollmentPage
    .mockResolvedValueOnce(response([1], 2))
    .mockRejectedValueOnce(new Error('Unavailable'))
    .mockResolvedValueOnce(response([], 1))
    .mockResolvedValueOnce(response([1], 1))
  render(<Harness initial={[1]} />)
  fireEvent.click(await screen.findByRole('button', { name: 'Siguiente' }))
  fireEvent.click(await screen.findByRole('button', { name: 'Reintentar' }))
  expect(
    (await screen.findByRole('checkbox', { name: /Course 1/ })).checked,
  ).toBe(true)
  expect(getEnrollmentPage.mock.calls.map(([page]) => page)).toEqual([
    1, 2, 2, 1,
  ])
})

it('locks selections during a save and exposes field errors to the form focus handler', async () => {
  render(
    <Harness initial={[1]} disabled errors={['Choose your own courses.']} />,
  )
  await screen.findByRole('checkbox', { name: /Course 1/ })
  const group = screen.getByRole('group', { name: 'Mis cursos y destacados' })
  expect(group.disabled).toBe(true)
  expect(group.getAttribute('aria-invalid')).toBe('true')
  expect(group.getAttribute('tabindex')).toBe('-1')
})

it('renders public featured courses in the supplied order without requesting private enrollments', () => {
  const { rerender } = render(
    <ProfileCourses courses={[course(2), course(1)]} />,
  )
  expect(
    screen.getAllByRole('heading', { level: 3 }).map((h) => h.textContent),
  ).toEqual(['Course 2', 'Course 1'])
  expect(getEnrollmentPage).not.toHaveBeenCalled()
  rerender(<ProfileCourses courses={[]} />)
  expect(
    screen.queryByRole('heading', { name: 'Cursos destacados' }),
  ).toBeNull()
})
