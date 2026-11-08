import { createBrowserRouter, type RouteObject } from 'react-router'
import { AppShell } from './layout/AppShell'
import { useSession } from './auth/SessionProvider'
import { Placeholder } from './pages/Placeholder'
import { ProfileRoute } from './pages/Profile'
import { RequireSignIn } from './auth/RequireSignIn'
import { SignUp } from './pages/account/SignUp'
import { SignIn } from './pages/account/SignIn'
import { ForgotPassword } from './pages/account/ForgotPassword'
import { VerifyEmail } from './pages/account/VerifyEmail'
import { ResetPassword } from './pages/account/ResetPassword'
import { Settings } from './pages/account/Settings'

function ShellLayout() {
  const { status, me, signOut } = useSession()
  // While loading, render signed out so nothing flashes.
  return <AppShell user={status === 'signed-in' ? me : null} onSignOut={() => void signOut()} />
}

function ShellLayout() {
  const { status, me, signOut } = useSession()
  // While loading, render signed out so nothing flashes.
  return <AppShell user={status === 'signed-in' ? me : null} onSignOut={() => void signOut()} />
}

const page = (path: string, name: string) => ({
  path,
  element: <Placeholder name={name} />,
})

export const routes: RouteObject[] = [
  {
    element: <ShellLayout />,
    children: [
      { index: true, element: <Placeholder name="Home" /> },
      page('categories/:slug', 'Category'),
      page('watch/:videoId', 'Watch'),
      { path: 'sign-up', element: <SignUp /> },
      { path: 'sign-in', element: <SignIn /> },
      { path: 'forgot-password', element: <ForgotPassword /> },
      { path: 'verify-email', element: <VerifyEmail /> },
      { path: 'reset-password', element: <ResetPassword /> },
      { path: 'settings', element: <RequireSignIn><Settings /></RequireSignIn> },
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
