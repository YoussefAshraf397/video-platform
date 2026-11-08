# reel web

React + TypeScript + Vite single-page app for the reel video platform. It talks to the Laravel API in `../api`.

```bash
cp .env.example .env     # VITE_API_URL, default http://localhost:8000
npm install
npm run dev              # http://localhost:3000 (port is fixed)
```

| Script | What |
|---|---|
| `npm run dev` | Vite dev server on port 3000 |
| `npm run build` | Typecheck and production build into `dist/` |
| `npm test` | Vitest and Testing Library |
| `npm run typecheck` | `tsc --noEmit` |
| `npm run api:types` | Regenerate `src/api/schema.d.ts` from `../api/openapi.json` (commit the result) |
| `npm run e2e` | Playwright end-to-end tests (needs the local stack, see `make up`) |

Styling is CSS Modules plus the design tokens in `src/styles/tokens.css` (the "Marquee" identity). Fonts are self-hosted through fontsource.
