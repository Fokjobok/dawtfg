import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import { MemoryRouter } from 'react-router'
import AccountPage from './AccountPage.jsx'
import { AuthProvider } from '../context/AuthContext.jsx'

function renderAccount() {
  return render(
    <MemoryRouter>
      <AuthProvider>
        <AccountPage />
      </AuthProvider>
    </MemoryRouter>,
  )
}

const user = {
  id: 1,
  name: 'Student',
  email: 'student@example.test',
  role: 'Student',
}

afterEach(() => {
  cleanup()
  document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/'
})

it('logs in using the CSRF cookie and displays the current identity', async () => {
  const request = vi
    .fn()
    .mockResolvedValueOnce(Response.json({ data: null }))
    .mockResolvedValueOnce(new Response(null, { status: 204 }))
    .mockResolvedValueOnce(Response.json({ data: user }))
  vi.stubGlobal('fetch', request)
  document.cookie = 'XSRF-TOKEN=csrf-test-token'
  renderAccount()

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
    .mockResolvedValueOnce(Response.json({ data: null }))
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
  renderAccount()

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

it('shows own profile navigation and the account-panel logout button', async () => {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue(Response.json({ data: user })),
  )
  renderAccount()
  await screen.findByRole('heading', { name: 'Mi perfil' })
  expect(
    screen.getByRole('link', { name: 'Editar perfil' }).getAttribute('href'),
  ).toBe('/profiles/1')
  expect(screen.queryByRole('link', { name: 'Gestionar cursos' })).toBeNull()
  expect(screen.getByRole('button', { name: 'Cerrar sesión' })).toBeTruthy()
})

it('reports session lookup errors without presenting a confirmed guest login', async () => {
  vi.stubGlobal(
    'fetch',
    vi
      .fn()
      .mockResolvedValue(
        Response.json({ error: 'Unavailable', code: 503 }, { status: 503 }),
      ),
  )
  renderAccount()
  await screen.findByRole('alert')
  expect(screen.queryByRole('button', { name: 'Entrar' })).toBeNull()
})
