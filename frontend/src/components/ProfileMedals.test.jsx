import { cleanup, render, screen, within } from '@testing-library/react'
import { afterEach, expect, it } from 'vitest'
import ProfileMedals from './ProfileMedals.jsx'

afterEach(cleanup)

const medals = [
  {
    id: 2,
    name: 'Survival',
    specialty: { id: 1, name: 'Tarkov' },
    image_url: null,
  },
  {
    id: 1,
    name: 'Tactics',
    specialty: { id: 2, name: 'Counter Strike' },
    image_url: '/api/v1/medals/1/image',
  },
  {
    id: 3,
    name: 'Teamwork',
    specialty: { id: 2, name: 'Counter Strike' },
    image_url: null,
  },
]

it('groups permitted medals by specialty and sorts the groups by name', () => {
  render(<ProfileMedals medals={medals} isOwner={false} />)
  expect(
    screen.getAllByRole('heading', { level: 3 }).map((h) => h.textContent),
  ).toEqual(['Counter Strike', 'Tarkov'])
  const group = screen.getByRole('region', { name: 'Counter Strike' })
  expect(within(group).getAllByRole('listitem')).toHaveLength(2)
  expect(within(group).queryByText('Survival')).toBeNull()
  expect(group.querySelector('img').getAttribute('alt')).toBe('')
  expect(group.querySelector('img').getAttribute('src')).toBe(
    '/api/v1/medals/1/image',
  )
})

it('keeps an empty state for the owner and omits the empty section for visitors', () => {
  const { rerender } = render(<ProfileMedals medals={[]} isOwner />)
  expect(screen.getByText('Todavía no has recibido medallas.')).toBeTruthy()
  rerender(<ProfileMedals medals={[]} isOwner={false} />)
  expect(screen.queryByRole('heading', { name: 'Medallas' })).toBeNull()
})
