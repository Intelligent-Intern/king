# UX6-02 Planning Image Dirty Worktree Classification

Source worktree: `/home/jochen/projects/king.site/worktrees/planning-image-call-app`

Source branch: `agent/planning-image-call-app`

Classification date: 2026-05-10

## Source Delta

The source worktree has two tracked edits and two untracked paths:

- `demo/call-app/README.md`
- `demo/video-chat/frontend-vue/tests/contract/call-app-package-layout-contract.mjs`
- `demo/call-app/image-planning/`
- `demo/video-chat/frontend-vue/tests/contract/call-app-image-planning-runtime-contract.mjs`

The untracked package uses the legacy app key and root
`image-planning`. The integrated package uses the canonical app key and root
`planning-image`.

## Classification

| Source value | Integrated status | Decision |
| --- | --- | --- |
| Generic package asset names, `public/<app-key>.css` and `public/<app-key>.js` | Already preserved in `demo/call-app/README.md` and the package layout contract. | Keep integrated wording. |
| Legacy package root `demo/call-app/image-planning/` and app key `image-planning` | Superseded by `demo/call-app/planning-image/` and app key `planning-image`. | Do not copy the legacy package into integration. |
| Runtime-ready manifest, MCP descriptor, CRDT schema, health descriptor, iframe entrypoint, stylesheet, and runtime asset checks | Covered by `call-app-package-layout-contract.mjs` and `call-app-planning-image-contract.mjs` for the canonical package. | Keep canonical package only. |
| Iframe bridge safety: `king.call_app.iframe.v1`, opaque sandbox, no primary session token, no parent auth material | Covered by the integrated planning-image contract and package metadata. | No additional runtime copy needed. |
| File upload, canvas rendering, wheel zoom, pointer pan, fit/reset-style viewport controls, bootstrap/replay, and CRDT append coverage | Covered by the integrated runtime contract, with stronger multi-image add/select/delete/export behavior. | Keep integrated runtime and contract. |
| Vite publication of every Call App package file through `walkFiles(callAppRoot)` | Already present in `vite.config.js`; preserved by the UX6-02 classification contract. | Keep integrated build behavior. |
| Non-whiteboard Call App iframe URL generation | Already present in `callAppWorkspaceState.js`; preserved by the UX6-02 classification contract. | Keep integrated host behavior. |
| Legacy `viewport.update` operation name | Superseded by canonical `planning_image.viewport` in the integrated CRDT schema. The current runtime treats viewport movement as local review state. | Preserve the canonical schema anchor; do not reintroduce the legacy operation name. |

## Cleanup Decision

No still-relevant non-media value remains only in the dirty source worktree
after this classification and contract are integrated. Until this UX6-02 commit
lands on the integration branch, preserve
`/home/jochen/projects/king.site/worktrees/planning-image-call-app` as evidence.
After this commit is integrated, the source dirty worktree can be removed; no
files from `demo/call-app/image-planning/` should be copied into integration.
