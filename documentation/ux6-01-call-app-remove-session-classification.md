# UX6-01 Call App Remove Session Classification

Source worktree inspected read-only:
`/home/jochen/projects/king.site/worktrees/call-app-remove-session`

Source branch: `agent/call-app-remove-session`

Dirty source file:
`demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppsSidebarPanel.vue`

## Classification

The source dirty diff is mostly superseded by current
`prod-kingrt-do-not-push-to-github` integration. Current prod already includes
the backend-backed remove flow, event forwarding through the left sidebar,
workspace layout exit on removal, participant-grant preservation, and contract
coverage from commit `825f99c5` (`Allow removing call apps from calls`).

The dirty source file is stale relative to current prod because it does not
contain the later participant permission-action button loop or the current
`session-removed` payload shape used by `WorkspaceShell`. It should not be
copied wholesale.

Accepted non-media residue extracted here:
- Preserve an explicit `data-call-app-remove-flow="backend-delete"` marker on
  the current remove action.
- Keep add/remove requests mutually exclusive by disabling and guarding removal
  while an attach request is submitting.
- Add an active-session title/ARIA label for the remove affordance.

Not extracted:
- The dirty diff's image icon reference, because the current sprint asked for
  non-media value only and current button styling is already integrated.
- The stale dirty event payload wrapper, because current prod already emits the
  backend result or `{ session_id }` fallback expected by integrated shell
  handling.

## Cleanup Decision

After this branch is merged back to local prod, the source dirty worktree can be
removed from a value-preservation standpoint. Its only dirty file has either
already been integrated or was superseded by stronger current implementation,
with the remaining non-media affordance value extracted and contract-pinned.

Do not remove the source worktree before this worker branch is integrated,
because the source checkout remains dirty until a human cleanup step deletes or
resets it.

## Proof Commands

```bash
git -C /home/jochen/projects/king.site/worktrees/call-app-remove-session status --short --branch
git -C /home/jochen/projects/king.site/worktrees/call-app-remove-session diff -- demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppsSidebarPanel.vue
git -C /home/jochen/projects/king.site/worktrees/ux6-01-call-app-remove-session diff --no-index -- /home/jochen/projects/king.site/worktrees/call-app-remove-session/demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppsSidebarPanel.vue /home/jochen/projects/king.site/worktrees/ux6-01-call-app-remove-session/demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppsSidebarPanel.vue
cd /home/jochen/projects/king.site/worktrees/ux6-01-call-app-remove-session/demo/video-chat/frontend-vue && node tests/contract/call-app-sidebar-contract.mjs
```
