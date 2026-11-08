import { useQuery } from '@tanstack/react-query'
import { api } from './client'
import type { Category } from './types'

/** The category list, fetched once per session. */
export function useCategories() {
  return useQuery({
    queryKey: ['categories'],
    staleTime: Infinity,
    queryFn: async () => (await api<{ items: Category[] }>('GET', '/v1/categories')).data.items,
  })
}
