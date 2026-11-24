import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import ToastProvider, { useToasts } from './ToastProvider.jsx'

function Controls() {
  const { notify } = useToasts()
  return (
    <>
      <main id="main-content" tabIndex={-1}>
        Content
      </main>
      {['success', 'error', 'warning', 'info'].map((type) => (
        <button key={type} onClick={() => notify(type, `Message ${type}`)}>
          Emit {type}
        </button>
      ))}
    </>
  )
}

function openToasts() {
  return render(
    <ToastProvider>
      <Controls />
    </ToastProvider>,
  )
}

afterEach(() => {
  cleanup()
  vi.useRealTimers()
})

it.each(['success', 'error', 'warning', 'info'])(
  'announces %s in an existing live region without stealing focus',
  (type) => {
    openToasts()
    expect(screen.getByRole('status')).toBeTruthy()
    expect(screen.getByRole('alert')).toBeTruthy()
    const trigger = screen.getByRole('button', { name: `Emit ${type}` })
    trigger.focus()
    fireEvent.click(trigger)
    const message = screen.getByText(`Message ${type}`)
    expect(
      message.closest(`[role="${type === 'error' ? 'alert' : 'status'}"]`),
    ).not.toBeNull()
    expect(document.activeElement).toBe(trigger)
  },
)

it('expires each notification after seven seconds without restarting earlier timers', () => {
  vi.useFakeTimers()
  openToasts()
  fireEvent.click(screen.getByRole('button', { name: 'Emit success' }))
  act(() => {
    vi.advanceTimersByTime(3000)
  })
  fireEvent.click(screen.getByRole('button', { name: 'Emit info' }))
  act(() => {
    vi.advanceTimersByTime(3999)
  })
  expect(screen.getByText('Message success')).toBeTruthy()
  act(() => {
    vi.advanceTimersByTime(1)
  })
  expect(screen.queryByText('Message success')).toBeNull()
  expect(screen.getByText('Message info')).toBeTruthy()
  act(() => {
    vi.advanceTimersByTime(3000)
  })
  expect(screen.queryByText('Message info')).toBeNull()
})

it('pauses while hovered or focused and then resumes only the remaining time', () => {
  vi.useFakeTimers()
  openToasts()
  const trigger = screen.getByRole('button', { name: 'Emit success' })
  fireEvent.click(trigger)
  const card = screen
    .getByText('Message success')
    .closest('[aria-atomic="true"]')
  act(() => {
    vi.advanceTimersByTime(2000)
  })
  fireEvent.mouseEnter(card)
  act(() => {
    vi.advanceTimersByTime(10000)
  })
  const close = screen.getByRole('button', {
    name: 'Cerrar notificación: Message success',
  })
  act(() => {
    close.focus()
  })
  fireEvent.mouseLeave(card)
  act(() => {
    vi.advanceTimersByTime(10000)
  })
  expect(screen.getByText('Message success')).toBeTruthy()
  act(() => {
    trigger.focus()
  })
  act(() => {
    vi.advanceTimersByTime(4999)
  })
  expect(screen.getByText('Message success')).toBeTruthy()
  act(() => {
    vi.advanceTimersByTime(1)
  })
  expect(screen.queryByText('Message success')).toBeNull()
})

it('allows manual keyboard dismissal and restores the previous focus', () => {
  openToasts()
  const trigger = screen.getByRole('button', { name: 'Emit success' })
  trigger.focus()
  fireEvent.click(trigger)
  const close = screen.getByRole('button', {
    name: 'Cerrar notificación: Message success',
  })
  act(() => {
    close.focus()
  })
  fireEvent.click(close)
  expect(screen.queryByText('Message success')).toBeNull()
  expect(document.activeElement).toBe(trigger)
})

it('cleans up timers when the provider unmounts', () => {
  vi.useFakeTimers()
  const view = openToasts()
  fireEvent.click(screen.getByRole('button', { name: 'Emit success' }))
  view.unmount()
  expect(vi.getTimerCount()).toBe(0)
})
