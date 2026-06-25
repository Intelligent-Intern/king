# Autoscaling

Autoscaling can be used procedurally through `king_autoscaling_*` and through
the `King\Autoscaling` OO facade.

## Internal Layout

The autoscaling runtime lives under `extension/src/autoscaling/runtime/` with
public contracts in `extension/include/autoscaling/`. The PHP arginfo,
`King\Autoscaling` method table, and function-table entries live beside the
runtime entry unit and are included by the extension bootstrap.

## Function, Example 1: Initialize Runtime and Read Status

```php
<?php
king_autoscaling_init([
    'provider' => 'hetzner',
    'min_instances' => 2,
    'max_instances' => 8,
    'scale_up_policy' => 'cpu_or_queue',
    'cooldown_seconds' => 120,
]);

king_autoscaling_start_monitoring();

$status = king_autoscaling_get_status();
$metrics = king_autoscaling_get_metrics();

var_dump($status['provider_mode'], $metrics);
```

## Function, Example 2: Node Lifecycle

```php
<?php
king_autoscaling_scale_up(1);
king_autoscaling_register_node(10042, 'worker-fra-10042');
king_autoscaling_mark_node_ready(10042);

$nodes = king_autoscaling_get_nodes();
var_dump($nodes);

king_autoscaling_drain_node(10042);
king_autoscaling_scale_down(1);
king_autoscaling_stop_monitoring();
```

## OO, Example 1: King\Autoscaling

```php
<?php
use King\Autoscaling;

Autoscaling::init([
    'provider' => 'hetzner',
    'min_instances' => 1,
    'max_instances' => 4,
]);

Autoscaling::startMonitoring();
var_dump(Autoscaling::getStatus());
Autoscaling::stopMonitoring();
```

## OO, Example 2: Controller Class

```php
<?php
use King\Autoscaling;

final class WorkerPoolController
{
    public function ensureCapacity(int $queueDepth): void
    {
        $status = Autoscaling::getStatus();

        if ($queueDepth > 100 && $status['current_instances'] < 8) {
            Autoscaling::scaleUp(1);
        }

        if ($queueDepth === 0 && $status['current_instances'] > 2) {
            Autoscaling::scaleDown(1);
        }
    }

    public function drain(int $serverId): void
    {
        Autoscaling::drainNode($serverId);
    }
}

$controller = new WorkerPoolController();
$controller->ensureCapacity(180);
```
