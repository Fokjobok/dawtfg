import { useState } from 'react'
import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import MedalPicker from './MedalPicker.jsx'
import { getMedals } from '../services/medals.js'

vi.mock('../services/medals.js', () => ({ getMedals: vi.fn() }))

const specialties = [
  { id: 1, name: 'Tarkov' },
  { id: 2, name: 'Counter Strike' },
]
const medal = { id: 7, name: 'Tactics' }
const page = (data, lastPage = 1) => ({ data, meta: { last_page: lastPage } })

function Harness({ refreshKey = 0, disabled = false }) {
  const [value, setValue] = useState('')
  return (
    <MedalPicker
      specialties={specialties}
      value={value}
      onChange={setValue}
      ownedIds={[7]}
      disabled={disabled}
      refreshKey={refreshKey}
    />
  )
}

beforeEach(() => {
  vi.resetAllMocks()
  getMedals.mockResolvedValue(page([medal]))
})
afterEach(cleanup)

function selectCategory(id) {
  fireEvent.change(screen.getByLabelText('Categoría de la medalla'), {
    target: { value: String(id) },
  })
}

it('waits for a category and clears the selected medal when navigating to another page', async () => {
  getMedals
    .mockResolvedValueOnce(page([medal], 2))
    .mockResolvedValueOnce(page([{ id: 8, name: 'Survival' }], 2))
  render(<Harness />)
  expect(getMedals).not.toHaveBeenCalled()
  expect(screen.getByLabelText('Medalla').disabled).toBe(true)
  selectCategory(1)
  await screen.findByRole('option', { name: 'Tactics — asignada' })
  expect(getMedals).toHaveBeenLastCalledWith('1', 1)
  fireEvent.change(screen.getByLabelText('Medalla'), { target: { value: '7' } })
  fireEvent.click(screen.getByRole('button', { name: 'Siguiente' }))
  expect(screen.getByLabelText('Medalla').value).toBe('')
  await screen.findByRole('option', { name: 'Survival' })
  expect(getMedals).toHaveBeenLastCalledWith('1', 2)
  expect(screen.getByRole('button', { name: 'Siguiente' }).disabled).toBe(true)
  getMedals.mockResolvedValueOnce(page([medal], 2))
  fireEvent.click(screen.getByRole('button', { name: 'Anterior' }))
  await screen.findByRole('option', { name: 'Tactics — asignada' })
  expect(getMedals).toHaveBeenLastCalledWith('1', 1)
})

it('resets pagination and discards a late response from the previous category', async () => {
  let resolveOld
  getMedals.mockImplementation((id) =>
    id === '1'
      ? new Promise((resolve) => {
          resolveOld = resolve
        })
      : Promise.resolve(page([{ id: 9, name: 'Current category' }])),
  )
  render(<Harness />)
  selectCategory(1)
  selectCategory(2)
  await screen.findByRole('option', { name: 'Current category' })
  await act(async () => resolveOld(page([medal], 4)))
  expect(screen.queryByRole('option', { name: /Tactics/ })).toBeNull()
  expect(getMedals).toHaveBeenLastCalledWith('2', 1)
  selectCategory('')
  expect(screen.getByLabelText('Medalla').disabled).toBe(true)
  expect(screen.queryByRole('option', { name: 'Current category' })).toBeNull()
})

it('offers retry after a failed query and distinguishes an empty category', async () => {
  getMedals
    .mockRejectedValueOnce(new Error('Unavailable'))
    .mockResolvedValueOnce(page([]))
  render(<Harness />)
  selectCategory(1)
  fireEvent.click(await screen.findByRole('button', { name: 'Reintentar' }))
  await screen.findByText('No hay medallas en esta categoría.')
  expect(screen.getByLabelText('Medalla').disabled).toBe(true)
  expect(getMedals).toHaveBeenCalledTimes(2)
})

it('reloads the current category after creation and locks its controls during mutations', async () => {
  const { rerender } = render(<Harness />)
  selectCategory(2)
  await screen.findByRole('option', { name: 'Tactics — asignada' })
  getMedals.mockResolvedValueOnce(page([{ id: 8, name: 'Created medal' }]))
  rerender(<Harness refreshKey={1} />)
  await screen.findByRole('option', { name: 'Created medal' })
  expect(getMedals).toHaveBeenCalledTimes(2)
  rerender(<Harness refreshKey={1} disabled />)
  await waitFor(() =>
    expect(screen.getByLabelText('Medalla').disabled).toBe(true),
  )
  expect(screen.getByLabelText('Categoría de la medalla').disabled).toBe(true)
})
