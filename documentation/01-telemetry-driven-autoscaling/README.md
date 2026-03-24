# 01: Telemetry-Driven Autoscaling

This example shows an unusual part of the current King runtime:
the autoscaling controller can make scale decisions directly from live
telemetry signals, even in the local runtime, and it exposes those
decisions through stable status and node-inventory surfaces.

## What This Example Demonstrates

- live telemetry metrics driving a controller tick
- a generic provider contract with an honest provider-mode flag
- capped scale-up via `scale_up_policy` plus `max_scale_step`
- cooldown enforcement across immediate follow-up ticks
- hysteresis-based scale-down when the load drops back under the floor
- managed-node inventory changes that can be inspected from PHP

The script uses the local simulated controller path on purpose.
That keeps it locally runnable while still exercising the same telemetry-driven
decision loop that the Hetzner path builds on.

## Files

- `controller_demo.php`
  Runs a short controller story: high load, cooldown, then low load.

## How To Run

From the repo root:

```bash
php8.4 \
  -d extension=/home/jochen/projects/king.site/king/extension/modules/king.so \
  -d king.security_allow_config_override=1 \
  documentation/01-telemetry-driven-autoscaling/controller_demo.php
```

If the extension is already installed system-wide, you can omit the explicit
`-d extension=...` flag.

## What To Look For

- `provider_mode` should be `simulated_local`
- the first controller tick should scale up
- the immediate second tick should not scale again because cooldown is active
- the final low-load tick should scale down
- `last_signal_source` should show that the decision was driven by telemetry

## Why It Is Interesting

This is not a toy "counter increment" demo.
The controller is consuming the same public telemetry API that userland can
write into, and the runtime then exposes:

- the last decision reason
- the active cooldown
- the managed node lifecycle
- the current controller-facing metrics snapshot

That makes it possible to prototype cluster-facing control logic directly in
PHP while still exercising the native extension surface.
