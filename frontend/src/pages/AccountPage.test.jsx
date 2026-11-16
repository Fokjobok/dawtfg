import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import AccountPage from './AccountPage.jsx'

const user = {
  id: 1,
  name: 'Student',
  email: 'student@example.test',
  role: 'Student',
}
const anonymous = () =>
  Response.json({ error: 'Unauthorized', code: 401 }, { status: 401 })

afterEach(() => {
  cleanup()
  document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/'
})

it('logs in using the CSRF cookie and displays the current identity', async () => {
  const request = vi
    .fn()
    .mockResolvedValueOnce(anonymous())
    .mockResolvedValueOnce(new Response(null, { status: 204 }))
    .mockResolvedValueOnce(Response.json({ data: user }))
  vi.stubGlobal('fetch', request)
  document.cookie = 'XSRF-TOKEN=csrf-test-token'
  render(<AccountPage />)

  await screen.findByRole('button', { name: 'Entrar' })
  fireEvent.change(screen.getByLabelText('Correo electrónico'), {
    target: { value: user.email },
  })
  fireEvent.change(screen.getByLabelText('Contraseña'), {
    target: { value: 'Password-only-for-tests-123' },
  })
  fireEvent.submit(
    screen.getByRole('button', { name: 'Entrar' }).closest('form'),
  )

  await screen.findByText(user.email)
  expect(request).toHaveBeenNthCalledWith(
    2,
    '/sanctum/csrf-cookie',
    expect.objectContaining({ credentials: 'same-origin' }),
  )
  expect(request).toHaveBeenNthCalledWith(
    3,
    '/api/v1/login',
    expect.objectContaining({
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': 'csrf-test-token',
      },
    }),
  )
})

it('shows field validation errors during registration', async () => {
  const request = vi
    .fn()
    .mockResolvedValueOnce(anonymous())
    .mockResolvedValueOnce(new Response(null, { status: 204 }))
    .mockResolvedValueOnce(
      Response.json(
        {
          error: 'Validation failed',
          code: 422,
          errors: { email: ['This email is already registered.'] },
        },
        { status: 422 },
      ),
    )
  vi.stubGlobal('fetch', request)
  render(<AccountPage />)

  fireEvent.click(
    await screen.findByRole('button', { name: 'Crear cuenta de estudiante' }),
  )
  expect(document.activeElement).toBe(screen.getByLabelText('Nombre'))
  fireEvent.submit(
    screen.getByRole('button', { name: 'Crear cuenta' }).closest('form'),
  )

  await screen.findByText('This email is already registered.')
  expect(document.activeElement).toBe(
    screen.getByLabelText('Correo electrónico'),
  )
  expect(
    screen
      .getByLabelText('Correo electrónico')
      .getAttribute('aria-describedby'),
  ).toBe('email-error')
  expect(
    screen.getByLabelText('Correo electrónico').getAttribute('aria-invalid'),
  ).toBe('true')
  expect(screen.getByRole('button', { name: 'Crear cuenta' }).disabled).toBe(
    false,
  )
})

it('returns to the login form when a session has expired', async () => {
  vi.stubGlobal(
    'fetch',
    vi
      .fn()
      .mockResolvedValueOnce(Response.json({ data: user }))
      .mockResolvedValueOnce(anonymous()),
  )
  render(<AccountPage />)

  fireEvent.click(
    await screen.findByRole('button', { name: 'Comprobar sesión' }),
  )
  await screen.findByText('La sesión ha caducado. Inicia sesión de nuevo.')
  expect(screen.getByRole('button', { name: 'Entrar' })).toBeDefined()
})

it('clears the displayed identity after logout', async () => {
  vi.stubGlobal(
    'fetch',
    vi
      .fn()
      .mockResolvedValueOnce(Response.json({ data: user }))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(new Response(null, { status: 204 })),
  )
  render(<AccountPage />)

  fireEvent.click(await screen.findByRole('button', { name: 'Cerrar sesión' }))
  await screen.findByText('Sesión cerrada.')
  expect(screen.queryByText(user.email)).toBeNull()
})
