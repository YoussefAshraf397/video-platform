import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClientProvider } from '@tanstack/react-query'
import { RouterProvider } from 'react-router'
import '@fontsource-variable/bricolage-grotesque'
import '@fontsource-variable/figtree'
import './styles/tokens.css'
import './styles/global.css'
import { SessionProvider } from './auth/SessionProvider'
import { router } from './router'
import { createQueryClient } from './api/queryClient'

const queryClient = createQueryClient()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <SessionProvider>
        <RouterProvider router={router} />
      </SessionProvider>
    </QueryClientProvider>
  </StrictMode>,
)
