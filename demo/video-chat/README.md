# King IIBIN WebSocket Demo

This demo currently ships as a single-page Vue application for the King
runtime's browser-side IIBIN/WebSocket slice.

What is wired today:

- manual WebSocket connect/disconnect against a King-compatible `/ws` endpoint
- binary IIBIN text-message exchange in the main chat panel
- synthetic stress runs with configurable duration, rate, and payload size
- live transport counters plus a simple IIBIN-vs-JSON size comparison

What is not yet an honest shipped contract here:

- persistent chat rooms or history
- a real production video-call flow
- packaged PWA/service-worker behavior
- standalone `stress-test` or `performance-test` Node scripts

## Commands

```bash
cd demo/video-chat
npm install
npm run dev
```

Useful commands:

- `npm run build`
- `npm run preview`
- `npm run type-check`
- `npm run test`

## Runtime Notes

- the default WebSocket target resolves from the current page origin and uses
  `/ws`
- for local development, the Vite dev server proxies `/ws` to
  `ws://localhost:8080`
- the demo depends on the browser-side IIBIN helpers in
  [src/lib/iibin.ts](/home/jochen/projects/king.site/king/demo/video-chat/src/lib/iibin.ts)

## Scope

This directory is a frontend demo surface, not the source of truth for King v1
runtime guarantees. Repo-level runtime and transport contracts stay in the
extension tests and root documentation.
