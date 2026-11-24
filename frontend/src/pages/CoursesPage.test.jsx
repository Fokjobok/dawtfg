import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import CoursesPage from './CoursesPage.jsx'
import ToastProvider from '../components/ToastProvider.jsx'
import { useAuth } from '../context/AuthContext.jsx'
import { getCourses } from '../services/courses.js'
import {
  enrollInCourse,
  getEnrollments,
  withdrawEnrollment,
} from '../services/enrollments.js'

vi.mock('../context/AuthContext.jsx', () => ({ useAuth: vi.fn() }))
vi.mock('../services/courses.js', () => ({ getCourses: vi.fn() }))
vi.mock('../services/enrollments.js', () => ({
  enrollInCourse: vi.fn(),
  getEnrollments: vi.fn(),
  withdrawEnrollment: vi.fn(),
}))

function course(id, price = '0.00') {
  return {
    id,
    title: `Course ${id}`,
    description: 'Course description.',
    price,
    level: 'beginner',
    cover_url: null,
    specialty: { id: 1, name: 'Programming' },
    coach: {
      id: 8,
      name: 'Test Coach',
      avatar_url: '/api/v1/profiles/8/avatar?v=current',
    },
  }
}

function response(data, page = 1, lastPage = 1) {
  return { data, meta: { current_page: page, last_page: lastPage } }
}

function student() {
  useAuth.mockReturnValue({ user: { id: 6, role: 'Student' }, status: 'ready' })
}

function openPage() {
  return render(
    <MemoryRouter>
      <ToastProvider>
        <CoursesPage />
      </ToastProvider>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.resetAllMocks()
  useAuth.mockReturnValue({ user: null, status: 'ready' })
  getCourses.mockResolvedValue(response([course(1), course(2, '19.90')]))
  getEnrollments.mockResolvedValue({ data: [] })
})

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

it('shows visitors the catalog and a login link for free courses without requesting private data', async () => {
  openPage()
  await screen.findByRole('heading', { name: 'Course 1' })
  expect(
    screen.getAllByRole('link', { name: 'Inicia sesión para inscribirte' })
      .length,
  ).toBe(1)
  expect(screen.queryByRole('button', { name: /Inscribirme en/ })).toBeNull()
  expect(getEnrollments).not.toHaveBeenCalled()
})

it.each(['Coach', 'Administrator'])(
  'does not offer enrollment or query Student data for %s',
  async (role) => {
    useAuth.mockReturnValue({ user: { id: 7, role }, status: 'ready' })
    openPage()
    await screen.findByRole('heading', { name: 'Course 1' })
    expect(
      screen.queryByRole('button', { name: /Inscribirme en|Darme de baja/ }),
    ).toBeNull()
    expect(
      screen.queryByRole('link', { name: 'Inicia sesión para inscribirte' }),
    ).toBeNull()
    expect(getEnrollments).not.toHaveBeenCalled()
  },
)

it('lets a Student enroll, cancel withdrawal and then confirm withdrawal using the enrollment ID', async () => {
  student()
  enrollInCourse.mockResolvedValue({ id: 88, course_id: 1 })
  withdrawEnrollment.mockResolvedValue(null)
  const confirm = vi
    .spyOn(window, 'confirm')
    .mockReturnValueOnce(false)
    .mockReturnValueOnce(true)
  openPage()
  const enroll = await screen.findByRole('button', {
    name: 'Inscribirme en Course 1',
  })
  expect(getEnrollments).toHaveBeenCalledExactlyOnceWith([1, 2])
  expect(
    screen.queryByRole('button', { name: 'Inscribirme en Course 2' }),
  ).toBeNull()
  fireEvent.click(enroll)
  const withdraw = await screen.findByRole('button', {
    name: 'Darme de baja de Course 1',
  })
  expect(enrollInCourse).toHaveBeenCalledExactlyOnceWith(1)
  fireEvent.click(withdraw)
  expect(withdrawEnrollment).not.toHaveBeenCalled()
  fireEvent.click(withdraw)
  await screen.findByRole('button', { name: 'Inscribirme en Course 1' })
  expect(withdrawEnrollment).toHaveBeenCalledExactlyOnceWith(88)
  expect(confirm.mock.calls[0][0]).toContain('#1: «Course 1»')
})

it('restores existing enrollment state including a course whose current price is positive', async () => {
  student()
  getEnrollments.mockResolvedValue({
    data: [
      { id: 77, course_id: 1 },
      { id: 78, course_id: 2 },
    ],
  })
  openPage()
  await screen.findByRole('button', { name: 'Darme de baja de Course 1' })
  expect(
    screen.getByRole('button', { name: 'Darme de baja de Course 2' }),
  ).toBeTruthy()
  expect(enrollInCourse).not.toHaveBeenCalled()
})

it('keeps public cards but blocks enrollment if the private lookup fails', async () => {
  student()
  getEnrollments.mockRejectedValue({ status: 500 })
  openPage()
  await screen.findByText(/No se pudieron consultar tus inscripciones/)
  expect(screen.getByRole('heading', { name: 'Course 1' })).toBeTruthy()
  const button = screen.getByRole('button', { name: 'Inscribirme en Course 1' })
  expect(button.disabled).toBe(true)
  fireEvent.click(button)
  expect(enrollInCourse).not.toHaveBeenCalled()
})

it('reports a rejected enrollment without inventing a successful inscription', async () => {
  student()
  enrollInCourse.mockRejectedValue({ status: 422 })
  openPage()
  fireEvent.click(
    await screen.findByRole('button', { name: 'Inscribirme en Course 1' }),
  )
  await screen.findByText(
    'Este curso no admite una nueva inscripción gratuita.',
  )
  expect(
    screen.queryByRole('button', { name: 'Darme de baja de Course 1' }),
  ).toBeNull()
  expect(
    screen.getByRole('button', { name: 'Inscribirme en Course 1' }).disabled,
  ).toBe(false)
})

it('blocks duplicate mutations, filters and pagination while enrollment is pending', async () => {
  student()
  let finish
  enrollInCourse.mockReturnValue(
    new Promise((resolve) => {
      finish = resolve
    }),
  )
  getCourses.mockResolvedValue(response([course(1), course(2)], 1, 2))
  openPage()
  const button = await screen.findByRole('button', {
    name: 'Inscribirme en Course 1',
  })
  fireEvent.click(button)
  expect(button.disabled).toBe(true)
  expect(
    screen.getByRole('button', { name: 'Inscribirme en Course 2' }).disabled,
  ).toBe(true)
  expect(screen.getByLabelText('Nivel').disabled).toBe(true)
  expect(screen.getByRole('button', { name: 'Siguiente' }).disabled).toBe(true)
  fireEvent.click(button)
  expect(enrollInCourse).toHaveBeenCalledTimes(1)
  await act(async () => {
    finish({ id: 99, course_id: 1 })
  })
  expect(
    screen.getByRole('button', { name: 'Darme de baja de Course 1' }).disabled,
  ).toBe(false)
  expect(screen.getByLabelText('Nivel').disabled).toBe(false)
})

it('resets pagination when filtering and requests private states only for each current page', async () => {
  student()
  getCourses.mockReset()
  getCourses
    .mockResolvedValueOnce(response([course(1), course(2)], 1, 2))
    .mockResolvedValueOnce(response([course(3)], 2, 2))
    .mockResolvedValueOnce(response([course(4)]))
  openPage()
  await screen.findByRole('heading', { name: 'Course 1' })
  fireEvent.click(screen.getByRole('button', { name: 'Siguiente' }))
  await screen.findByRole('heading', { name: 'Course 3' })
  fireEvent.change(screen.getByLabelText('Nivel'), {
    target: { value: 'expert' },
  })
  await screen.findByRole('heading', { name: 'Course 4' })
  expect(getCourses.mock.calls).toEqual([
    [1, { level: '' }],
    [2, { level: '' }],
    [1, { level: 'expert' }],
  ])
  expect(getEnrollments.mock.calls).toEqual([[[1, 2]], [[3]], [[4]]])
})
