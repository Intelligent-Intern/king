# Autoscaling

Autoscaling kann procedural ueber `king_autoscaling_*` und OO ueber
`King\Autoscaling` genutzt werden.

## Function, Beispiel 1: Runtime initialisieren und Status lesen

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

## Function, Beispiel 2: Node Lifecycle

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

## OO, Beispiel 1: King\Autoscaling

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

## OO, Beispiel 2: Controller-Klasse

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
