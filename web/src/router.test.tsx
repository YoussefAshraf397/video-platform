import { render, screen } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { routes } from './router'

function at(path: string) {
  render(<RouterProvider router={createMemoryRouter(routes, { initialEntries: [path] })} />)
}

describe('routes', () => {
  it('renders the profile for /@handle, including dots', () => {
    at('/@mona.makes')
    expect(screen.getByRole('heading', { name: 'Profile @mona.makes' })).toBeInTheDocument()
  })

  it('shows Not found for a bare segment', () => {
    at('/mona')
    expect(screen.getByRole('heading', { name: 'Not found' })).toBeInTheDocument()
  })

  it('shows Not found for an empty handle', () => {
    at('/@')
    expect(screen.getByRole('heading', { name: 'Not found' })).toBeInTheDocument()
  })

  it.each([
    ['/verify-email?token=abc', 'Verify email'],
    ['/reset-password?token=abc', 'Reset password'],
  ])('renders %s', (path, name) => {
    at(path)
    expect(screen.getByRole('heading', { name })).toBeInTheDocument()
  })
})
