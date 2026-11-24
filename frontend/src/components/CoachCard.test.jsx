import { cleanup, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, expect, it } from 'vitest'
import CoachCard from './CoachCard.jsx'

afterEach(cleanup)

it('links the Coach identity to its public profile and renders its decorative avatar', () => {
  const { container } = render(
    <MemoryRouter>
      <CoachCard
        coach={{
          id: 8,
          name: 'Public Coach',
          avatar_url: '/api/v1/profiles/8/avatar?v=current',
        }}
      />
    </MemoryRouter>,
  )
  const link = screen.getByRole('link', { name: /Public Coach/ })
  expect(screen.getByText('Coach')).toBeTruthy()
  expect(link.getAttribute('href')).toBe('/profiles/8')
  const avatar = container.querySelector('img')
  expect(avatar.getAttribute('src')).toBe('/api/v1/profiles/8/avatar?v=current')
  expect(avatar.getAttribute('alt')).toBe('')
  expect(screen.getByText('Public Coach')).toBeTruthy()
})

it('keeps the identity link without displaying an absent avatar or replacement label', () => {
  const { container } = render(
    <MemoryRouter>
      <CoachCard coach={{ id: 8, name: 'Public Coach', avatar_url: null }} />
    </MemoryRouter>,
  )
  expect(screen.getByRole('link', { name: /Public Coach/ })).toBeTruthy()
  expect(screen.getByText('Coach')).toBeTruthy()
  expect(container.querySelector('img')).toBeNull()
  expect(screen.queryByText(/Sin avatar/)).toBeNull()
})

it('shows real medal images with accessible names and uses the name when an image is absent', () => {
  render(
    <MemoryRouter>
      <CoachCard
        coach={{
          id: 8,
          name: 'Public Coach',
          avatar_url: null,
          medals: [
            { id: 1, name: 'Tactics', image_url: '/api/v1/medals/1/image' },
            { id: 2, name: 'Teamwork', image_url: null },
          ],
        }}
      />
    </MemoryRouter>,
  )
  expect(screen.getByRole('img', { name: 'Tactics' }).getAttribute('src')).toBe(
    '/api/v1/medals/1/image',
  )
  expect(screen.getByText('Teamwork')).toBeTruthy()
  expect(screen.getAllByRole('img')).toHaveLength(1)
})
