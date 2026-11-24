import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from './App.jsx'
import { AuthProvider } from './context/AuthContext.jsx'
import { getSession } from './services/auth.js'

vi.mock('./services/auth.js', async (importOriginal) => ({
  ...(await importOriginal()),
  getSession: vi.fn(),
}))

beforeEach(() => {
  getSession.mockReset()
  getSession.mockResolvedValue(null)
})

afterEach(cleanup)

async function renderRoute(path = '/') {
  await act(async () =>
    render(
      <MemoryRouter initialEntries={[path]}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>,
    ),
  )
}

describe('App', () => {
  it('checks the API and reports a successful connection', async () => {
    const request = vi.fn().mockResolvedValue(Response.json({ status: 'ok' }))
    vi.stubGlobal('fetch', request)
    await renderRoute()

    const button = screen.getByRole('button', { name: 'Comprobar conexión' })
    fireEvent.click(button)

    expect(button.disabled).toBe(true)
    expect(
      screen.getByText('Comprobando la conexión…').getAttribute('role'),
    ).toBe('status')
    await screen.findByText('La API está disponible.')
    expect(button.disabled).toBe(false)
    expect(request).toHaveBeenCalledExactlyOnceWith(
      '/api/v1/health',
      expect.objectContaining({ headers: { Accept: 'application/json' } }),
    )
  })

  it('shows an error and allows a retry after an unsuccessful response', async () => {
    const request = vi
      .fn()
      .mockResolvedValueOnce(new Response(null, { status: 503 }))
      .mockResolvedValueOnce(Response.json({ status: 'ok' }))
    vi.stubGlobal('fetch', request)
    await renderRoute()

    const button = screen.getByRole('button', { name: 'Comprobar conexión' })
    fireEvent.click(button)
    await screen.findByText(/No se pudo contactar con la API/)
    expect(button.disabled).toBe(false)

    fireEvent.click(button)
    await screen.findByText('La API está disponible.')
    expect(request).toHaveBeenCalledTimes(2)
  })

  it('offers a way back from an unknown route', async () => {
    await renderRoute('/pagina-inexistente')

    expect(
      screen.getByRole('heading', { name: 'Página no encontrada' }),
    ).toBeDefined()
    fireEvent.click(screen.getByRole('link', { name: 'Volver al inicio' }))
    expect(
      screen.getByRole('heading', { name: 'Formación y coaching' }),
    ).toBeDefined()
  })
})
