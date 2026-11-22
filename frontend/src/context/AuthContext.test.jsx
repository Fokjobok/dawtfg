import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { AuthProvider, useAuth } from './AuthContext.jsx'
import { getSession } from '../services/auth.js'

vi.mock('../services/auth.js', () => ({ getSession: vi.fn() }))

const user = {
  id: 1,
  name: 'Student',
  email: 'student@example.test',
  role: 'Student',
  avatar_url: null,
}

function SessionConsumer({ label, controls = false }) {
  const { user, setUser, status } = useAuth()

  return (
    <div>
      <output aria-label={label}>
        {status}:{user?.name ?? 'guest'}
      </output>
      {controls && (
        <>
          <button
            onClick={() =>
              setUser((current) => ({ ...current, name: 'Updated' }))
            }
          >
            Update identity
          </button>
          <button onClick={() => setUser(null)}>Clear identity</button>
        </>
      )}
    </div>
  )
}

function openConsumers() {
  return render(
    <AuthProvider>
      <SessionConsumer label="Account" controls />
      <SessionConsumer label="Header" />
    </AuthProvider>,
  )
}

beforeEach(() => vi.resetAllMocks())
afterEach(cleanup)

it('distinguishes an unresolved session from a confirmed visitor', async () => {
  let resolveSession
  getSession.mockReturnValue(
    new Promise((resolve) => {
      resolveSession = resolve
    }),
  )
  openConsumers()
  expect(screen.getByLabelText('Account').textContent).toBe('loading:guest')
  await act(async () => resolveSession(null))
  expect(screen.getByLabelText('Account').textContent).toBe('ready:guest')
  expect(screen.getByLabelText('Header').textContent).toBe('ready:guest')
  expect(getSession).toHaveBeenCalledTimes(1)
})

it('reports a failed session query instead of treating it as a confirmed visitor', async () => {
  getSession.mockRejectedValue(new Error('Unavailable'))
  openConsumers()
  await screen.findAllByText('error:guest')
  expect(screen.getByLabelText('Account').textContent).toBe('error:guest')
  expect(screen.getByLabelText('Header').textContent).toBe('error:guest')
})

it('shares identity changes and clearing across consumers without another session query', async () => {
  getSession.mockResolvedValue(user)
  openConsumers()
  await screen.findAllByText('ready:Student')
  fireEvent.click(screen.getByRole('button', { name: 'Update identity' }))
  expect(screen.getByLabelText('Account').textContent).toBe('ready:Updated')
  expect(screen.getByLabelText('Header').textContent).toBe('ready:Updated')
  fireEvent.click(screen.getByRole('button', { name: 'Clear identity' }))
  expect(screen.getByLabelText('Account').textContent).toBe('ready:guest')
  expect(screen.getByLabelText('Header').textContent).toBe('ready:guest')
  expect(getSession).toHaveBeenCalledTimes(1)
})
