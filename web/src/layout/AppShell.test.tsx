import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { http, HttpResponse } from 'msw'
import { AppShell } from './AppShell'
import { API, server } from '../test/msw'

beforeEach(() => {
  server.use(
    http.get(`${API}/v1/categories`, () =>
      HttpResponse.json({ items: [{ slug: 'music', name: 'Music' }, { slug: 'gaming', name: 'Gaming' }] }),
    ),
  )
})

type User = { handle: string; display_name: string } | null

function renderShell(user?: User, onSignOut?: () => void) {
  const router = createMemoryRouter([
    {
      path: '/',
      element: <AppShell user={user} onSignOut={onSignOut} />,
      children: [{ index: true, element: <p>Page body</p> }],
    },
  ])
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  )
}

describe('AppShell', () => {
  it('shows the logo linking home, a main landmark and the page', () => {
    renderShell()
    expect(screen.getByRole('link', { name: 'reel' })).toHaveAttribute('href', '/')
    expect(screen.getByRole('main')).toHaveTextContent('Page body')
    expect(screen.getByRole('link', { name: 'Home' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Categories' })).toBeInTheDocument()
  })

  it('offers Sign in and Upload when signed out', () => {
    renderShell(null)
    expect(screen.getByRole('link', { name: 'Sign in' })).toHaveAttribute('href', '/sign-in')
    expect(screen.getByRole('link', { name: 'Upload' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Account menu' })).not.toBeInTheDocument()
  })

  it('shows Upload and an avatar menu when signed in', async () => {
    const onSignOut = vi.fn()
    const user = userEvent.setup()
    renderShell({ handle: 'mona.makes', display_name: 'Mona Haddad' }, onSignOut)

    expect(screen.queryByRole('link', { name: 'Sign in' })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Upload' })).toBeInTheDocument()

    const trigger = screen.getByRole('button', { name: 'Account menu' })
    expect(trigger).not.toHaveAttribute('aria-haspopup')
    expect(trigger).toHaveAttribute('aria-expanded', 'false')
    expect(screen.queryByRole('link', { name: 'Studio' })).not.toBeInTheDocument()

    await user.click(trigger)
    expect(trigger).toHaveAttribute('aria-expanded', 'true')
    expect(trigger).toHaveAttribute('aria-controls')
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Studio' })).toHaveAttribute('href', '/studio')
    expect(screen.getByRole('link', { name: 'Settings' })).toHaveAttribute('href', '/settings')

    await user.keyboard('{Escape}')
    expect(screen.queryByRole('link', { name: 'Studio' })).not.toBeInTheDocument()
    expect(trigger).toHaveFocus()

    await user.click(trigger)
    await user.click(screen.getByRole('button', { name: 'Sign out' }))
    expect(onSignOut).toHaveBeenCalledOnce()
    expect(screen.queryByRole('link', { name: 'Studio' })).not.toBeInTheDocument()
  })

  it('closes the menu when focus leaves it', async () => {
    const user = userEvent.setup()
    renderShell({ handle: 'mona.makes', display_name: 'Mona Haddad' })
    await user.click(screen.getByRole('button', { name: 'Account menu' }))
    expect(screen.getByRole('link', { name: 'Studio' })).toBeInTheDocument()
    await user.tab({ shift: true })
    expect(screen.getByRole('link', { name: 'Upload' })).toHaveFocus()
    expect(screen.queryByRole('link', { name: 'Studio' })).not.toBeInTheDocument()
  })

  it('keeps the menu open when clicking non-focusable content inside it', async () => {
    server.use(http.get(`${API}/v1/categories`, async () => new Promise(() => {})))
    const user = userEvent.setup()
    renderShell()
    await user.click(screen.getByRole('button', { name: 'Categories' }))
    await user.click(screen.getByText('Loading categories…'))
    expect(screen.getByText('Loading categories…')).toBeInTheDocument()
  })

  it('lists categories from the API and closes when one is chosen', async () => {
    const user = userEvent.setup()
    renderShell()
    const trigger = screen.getByRole('button', { name: 'Categories' })
    await user.click(trigger)
    expect(trigger).toHaveAttribute('aria-expanded', 'true')
    const music = await screen.findByRole('link', { name: 'Music' })
    expect(music).toHaveAttribute('href', '/categories/music')
    expect(screen.getByRole('link', { name: 'Gaming' })).toBeInTheDocument()
    await user.click(music)
    expect(screen.queryByRole('link', { name: 'Music' })).not.toBeInTheDocument()
    expect(trigger).toHaveAttribute('aria-expanded', 'false')
  })
})
