import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from './App.jsx'

afterEach(cleanup)

function renderRoute(path = '/') {
  render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  )
}

describe('App', () => {
  it('checks the API and reports a successful connection', async () => {
    const request = vi.fn().mockResolvedValue(Response.json({ status: 'ok' }))
    vi.stubGlobal('fetch', request)
    renderRoute()

    const button = screen.getByRole('button', { name: 'Comprobar conexión' })
    fireEvent.click(button)

    expect(button.disabled).toBe(true)
    expect(screen.getByRole('status').textContent).toBe(
      'Comprobando la conexión…',
    )
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
    renderRoute()

    const button = screen.getByRole('button', { name: 'Comprobar conexión' })
    fireEvent.click(button)
    await screen.findByText(/No se pudo contactar con la API/)
    expect(button.disabled).toBe(false)

    fireEvent.click(button)
    await screen.findByText('La API está disponible.')
    expect(request).toHaveBeenCalledTimes(2)
  })

  it('offers a way back from an unknown route', () => {
    renderRoute('/pagina-inexistente')

    expect(
      screen.getByRole('heading', { name: 'Página no encontrada' }),
    ).toBeDefined()
    fireEvent.click(screen.getByRole('link', { name: 'Volver al inicio' }))
    expect(
      screen.getByRole('heading', { name: 'Formación y coaching' }),
    ).toBeDefined()
  })
})
