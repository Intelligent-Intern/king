# Local Image Push

Manual helper to build and push all release images from a local machine in parallel.

Script: [`push-all.sh`](./push-all.sh)

## Typical usage

```bash
# 1) Login

docker login ghcr.io
docker login   # only if you also want Docker Hub push

# 2) Run full local publish (GHCR base/runtime/demo/video-chat)
./infra/local-image-push/push-all.sh --release-version v1.0.6-beta.1

# 3) Optional: also push Docker Hub runtime tag(s)
./infra/local-image-push/push-all.sh \
  --release-version v1.0.6-beta.1 \
  --include-dockerhub-runtime
```

## Notes

- The script uses `docker buildx` and runs jobs in parallel.
- Logs are written to `infra/local-image-push/logs/`.
- Runtime/demo/video-chat-backend builds need release package artifacts in:
  - `dist/docker-packages/php<version>/linux-amd64/*.tar.gz`
  - `dist/docker-packages/php<version>/linux-arm64/*.tar.gz`

Run `./infra/local-image-push/push-all.sh --help` for all flags.
