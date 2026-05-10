# IAM11-19 Local Deploy Gate

Scope:
- Analysis doc and local script only.
- No production deploy.
- No push, DNS changes, certbot calls, or remote mutation.
- No Background, Gossip, SFU, MediaSecurity, or BTGF files touched.

Gate anchor:
- `demo/video-chat/scripts/local-deploy-gate.sh`

Default local command:

```bash
demo/video-chat/scripts/local-deploy-gate.sh
```

The default command runs only local checks:
- backend IAM/call-access contracts;
- deploy-script syntax and idempotency-marker validation without running deploy;
- frontend package/lockfile validation;
- optional `docker compose config --quiet` when Docker Compose is available;
- frontend IAM contract gate;
- frontend release-gate package contract;
- frontend production build.

Focused equivalent commands:

```bash
bash -n demo/video-chat/scripts/deploy.sh
bash -n demo/video-chat/scripts/lib/deploy-hetzner.sh
bash -n demo/video-chat/scripts/lib/deploy-remote-status.sh
bash -n demo/video-chat/scripts/deploy-smoke.sh
bash -n demo/video-chat/scripts/smoke.sh
bash -n demo/video-chat/scripts/local-deploy-gate.sh
demo/video-chat/scripts/check-deploy-idempotency.sh
```

```bash
demo/video-chat/backend-king-php/tests/call-access-session-contract.sh
demo/video-chat/backend-king-php/tests/call-access-privacy-contract.sh
demo/video-chat/backend-king-php/tests/call-access-strong-mismatch-privacy-contract.sh
demo/video-chat/backend-king-php/tests/call-access-membership-removal-contract.sh
demo/video-chat/backend-king-php/tests/call-access-stale-organization-role-contract.sh
demo/video-chat/backend-king-php/tests/call-access-session-fixation-contract.sh
demo/video-chat/backend-king-php/tests/call-access-session-route-guard-contract.sh
demo/video-chat/backend-king-php/tests/call-owner-transfer-lifecycle-contract.sh
```

```bash
cd demo/video-chat/frontend-vue
npm run test:contract:iam-call-access
npm run test:e2e:release-gate
npm run build
```

Online smoke separation:

```bash
demo/video-chat/scripts/local-deploy-gate.sh --print-online-smoke
```

The online smoke is not part of the local gate. It is guarded by an explicit
environment opt-in and only delegates to `deploy-smoke.sh`; it still does not
deploy, change DNS, or request certificates:

```bash
VIDEOCHAT_LOCAL_DEPLOY_GATE_ALLOW_ONLINE_SMOKE=1 \
  demo/video-chat/scripts/local-deploy-gate.sh --online-smoke
```

Independent safety review:
- Reviewed `demo/video-chat/scripts/local-deploy-gate.sh` as a local deploy gate,
  not as a production deployment entrypoint.
- Reviewed the default `local` mode call graph for prohibited operations:
  `validate_package_and_config`, `run_backend_contracts`, and
  `run_frontend_gate`.
- Reviewed `demo/video-chat/scripts/check-deploy-idempotency.sh`, which is
  executed by the local gate and uses file existence checks, fixed-string
  marker checks, and `bash -n` syntax validation only.
- Reviewed the frontend scripts reached by the local gate:
  `test:contract:iam-call-access`, `test:e2e:release-gate`, and `build`.
- Reviewed the backend shell contracts reached by the local gate; each wrapper
  checks for local `pdo_sqlite` availability and executes its paired local PHP
  contract.

Findings:
- PASS: default mode is local-only. The script defaults `MODE` to `local` and
  the default case logs `no push, no deploy, no DNS, no certbot, no online
  smoke` before running local validation.
- PASS: no push path found. The reviewed gate and default delegated commands do
  not call `git push`, `npm publish`, registry publish commands, or equivalent
  remote publication commands.
- PASS: no deploy path found in default mode. The gate syntax-checks
  `deploy.sh`, `deploy-smoke.sh`, and deploy helpers with `bash -n`; it does
  not invoke `deploy.sh wizard`, `deploy.sh prepare`, `deploy.sh deploy`, or
  `deploy-smoke.sh` from the default path.
- PASS: no DNS mutation path found in default mode. DNS-related deploy helper
  content is checked only as text markers by `check-deploy-idempotency.sh`; the
  local gate does not call Hetzner DNS, `hcloud`, `dig`, `nslookup`, Route 53,
  Cloudflare, or other DNS mutation commands.
- PASS: no certbot path found in default mode. Certbot references are validated
  as syntax or text markers only; the gate does not execute `certbot`.
- PASS: online smoke is separated from the local gate. `--print-online-smoke`
  only prints the opt-in command. `--online-smoke` requires
  `VIDEOCHAT_LOCAL_DEPLOY_GATE_ALLOW_ONLINE_SMOKE=1` before delegating to
  `deploy-smoke.sh`.
- Residual local side effects are expected for a local gate: `npm run build`
  may update local build output, PHP and Node contracts may read repo-local
  fixtures, and `docker compose config --quiet` may inspect the local Docker
  environment when Docker Compose is installed. These are not push, deploy, DNS,
  or certbot operations.

Verification performed:

```bash
demo/video-chat/scripts/local-deploy-gate.sh --print-online-smoke
```

Result: printed the online smoke instructions only and did not run deploy,
DNS, certbot, or production smoke.
