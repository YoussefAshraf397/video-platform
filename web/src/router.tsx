import { createBrowserRouter, type RouteObject } from 'react-router'
import { AppShell } from './layout/AppShell'
import { Placeholder } from './pages/Placeholder'
import { ProfileRoute } from './pages/Profile'

const page = (path: string, name: string) => ({
  path,
  element: <Placeholder name={name} />,
})

export const routes: RouteObject[] = [
  {
    element: <AppShell />,
    children: [
      { index: true, element: <Placeholder name="Home" /> },
      page('categories/:slug', 'Category'),
      page('watch/:videoId', 'Watch'),
      page('sign-up', 'Sign up'),
      page('sign-in', 'Sign in'),
      page('forgot-password', 'Forgot password'),
      page('verify-email', 'Verify email'),
      page('reset-password', 'Reset password'),
      page('settings', 'Settings'),
      page('studio', 'Studio'),
      page('studio/upload', 'Upload'),
      page('studio/videos/:videoId', 'Edit video'),
      // React Router has no partial segments, so `/@handle` is matched as `:at` and checked in the element.
      { path: ':at', element: <ProfileRoute /> },
      page('*', 'Not found'),
    ],
  },
]

export const router = createBrowserRouter(routes)
