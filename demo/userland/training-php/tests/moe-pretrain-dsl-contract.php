<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/TrainingControlPlane.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

$moe = [
    'type' => 'moe',
    'name' => 'moe-pretrain-contract-model',
    'experts' => 256,
    'router' => 'top2',
];

$run = Training::distributed('moe-pretrain-run-2026-05-04')
    ->model($moe)
    ->data(
        Training\Data::objectStore('king://datasets/tokens/v9')
            ->format('iibin.tokens.v1')
            ->shuffle(seed: 1337)
            ->prefetch(window: 128)
    )
    ->optimizer(
        Optimizer::adamw()
            ->lr(3e-4)
            ->betas(0.9, 0.95)
            ->weightDecay(0.1)
            ->gradientClipping(1.0)
    )
    ->loss(
        Loss::crossEntropy()
            ->ignoreIndex(-100)
    )
    ->parallelism(
        Training\Parallelism::hybrid()
            ->dataParallel(128)
            ->tensorParallel(4)
            ->expertParallel(64)
            ->pipelineParallel(2)
            ->collectives('nccl')
            ->rendezvous('king://runs/moe-pretrain-run-2026-05-04/rdzv')
    )
    ->schedule(
        Training\Schedule::steps(1_000_000)
            ->microBatchSize(2)
            ->globalBatchTokens(8_000_000)
            ->warmupSteps(2000)
            ->cosineDecay()
    )
    ->checkpointing(
        Training\Checkpointing::objectStore()
            ->target('king://moe-prod/checkpoints/pretrain')
            ->everySteps(1000)
            ->async()
            ->resumeAutomatically()
    )
    ->placement(
        Placement::distributed()
            ->nodePool('gpu-h100')
            ->requireGpuMemory('>=80GB')
            ->spreadAcrossRacks()
            ->coLocateHotExperts()
    )
    ->failurePolicy(
        FailurePolicy::training()
            ->elasticRanks(min: 512, max: 2048)
            ->reconstructMissingExpertFromCheckpoint()
            ->quarantineBadWorkers()
            ->resumeFromLastConsistentCheckpoint()
    )
    ->start();

$plan = $run->plan();
$watch = $run->watch();
$envelope = $run->iibinEnvelope();
$persistence = $run->persistPlan();

assert_same('king.training.distributed.v1', $plan['contract'], 'training contract marker mismatch');
assert_same('moe-pretrain-run-2026-05-04', $plan['run_id'], 'run id mismatch');
assert_same('moe', $plan['model']['type'], 'model type mismatch');
assert_same('king://datasets/tokens/v9', $plan['data']['uri'], 'dataset URI mismatch');
assert_same('iibin.tokens.v1', $plan['data']['format'], 'dataset format mismatch');
assert_same('king_object_store', $plan['data']['storage']['runtime'], 'dataset storage runtime mismatch');
assert_same('king_object_store_get_to_stream', $plan['data']['storage']['read_api'], 'dataset read API mismatch');
assert_same('iibin', $plan['data']['codec']['runtime'], 'dataset codec runtime mismatch');
assert_same('king_proto_decode_batch', $plan['data']['codec']['decode_api'], 'dataset decode API mismatch');
assert_same(65536, $plan['data']['codec']['batch_limit'], 'dataset IIBIN batch limit mismatch');
assert_same(1337, $plan['data']['shuffle']['seed'], 'shuffle seed mismatch');
assert_same(128, $plan['data']['prefetch']['window'], 'prefetch window mismatch');
assert_same('adamw', $plan['optimizer']['type'], 'optimizer type mismatch');
assert_same(3e-4, $plan['optimizer']['lr'], 'optimizer lr mismatch');
assert_same([0.9, 0.95], $plan['optimizer']['betas'], 'optimizer betas mismatch');
assert_same(0.1, $plan['optimizer']['weight_decay'], 'optimizer weight decay mismatch');
assert_same(1.0, $plan['optimizer']['gradient_clipping'], 'optimizer gradient clipping mismatch');
assert_same('cross_entropy', $plan['loss']['type'], 'loss mismatch');
assert_same(-100, $plan['loss']['ignore_index'], 'ignore index mismatch');
assert_same('hybrid', $plan['parallelism']['type'], 'parallelism type mismatch');
assert_same(128, $plan['parallelism']['data_parallel'], 'data parallel mismatch');
assert_same(4, $plan['parallelism']['tensor_parallel'], 'tensor parallel mismatch');
assert_same(64, $plan['parallelism']['expert_parallel'], 'expert parallel mismatch');
assert_same(2, $plan['parallelism']['pipeline_parallel'], 'pipeline parallel mismatch');
assert_same('nccl', $plan['parallelism']['collectives'], 'collectives mismatch');
assert_same('king://runs/moe-pretrain-run-2026-05-04/rdzv', $plan['parallelism']['rendezvous'], 'rendezvous mismatch');
assert_same('websocket', $plan['parallelism']['coordination']['transport'], 'coordination transport mismatch');
assert_same('iibin', $plan['parallelism']['coordination']['frame_codec'], 'coordination codec mismatch');
assert_same('king_client_websocket_connect', $plan['parallelism']['coordination']['connect_api'], 'coordination connect API mismatch');
assert_same('king_client_websocket_receive', $plan['parallelism']['coordination']['receive_api'], 'coordination receive API mismatch');
assert_same(65536, $plan['parallelism']['coordination']['event_batch_limit'], 'coordination event batch limit mismatch');
assert_same(1_000_000, $plan['schedule']['steps'], 'schedule steps mismatch');
assert_same(2, $plan['schedule']['micro_batch_size'], 'micro batch mismatch');
assert_same(8_000_000, $plan['schedule']['global_batch_tokens'], 'global batch tokens mismatch');
assert_same(2000, $plan['schedule']['warmup_steps'], 'warmup steps mismatch');
assert_same('cosine', $plan['schedule']['decay'], 'decay mismatch');
assert_same('king://moe-prod/checkpoints/pretrain', $plan['checkpointing']['target'], 'checkpoint target mismatch');
assert_same(1000, $plan['checkpointing']['every_steps'], 'checkpoint interval mismatch');
assert_true($plan['checkpointing']['async'], 'checkpoint async flag missing');
assert_true($plan['checkpointing']['resume_automatically'], 'checkpoint resume flag missing');
assert_same('king_object_store', $plan['checkpointing']['storage']['runtime'], 'checkpoint storage runtime mismatch');
assert_same('king_object_store_begin_resumable_upload', $plan['checkpointing']['storage']['write_api'], 'checkpoint write API mismatch');
assert_same('king_object_store_complete_resumable_upload', $plan['checkpointing']['storage']['complete_api'], 'checkpoint complete API mismatch');
assert_same('gpu-h100', $plan['placement']['node_pool'], 'node pool mismatch');
assert_same('>=80GB', $plan['placement']['gpu_memory'], 'GPU memory mismatch');
assert_true($plan['placement']['spread_across_racks'], 'rack spread flag missing');
assert_true($plan['placement']['co_locate_hot_experts'], 'hot expert colocation flag missing');
assert_same(['min' => 512, 'max' => 2048], $plan['failure_policy']['elastic_ranks'], 'elastic ranks mismatch');
assert_true($plan['failure_policy']['reconstruct_missing_expert_from_checkpoint'], 'expert reconstruction flag missing');
assert_true($plan['failure_policy']['quarantine_bad_workers'], 'worker quarantine flag missing');
assert_true($plan['failure_policy']['resume_from_last_consistent_checkpoint'], 'checkpoint resume policy missing');
assert_same('control_plane_plan', $plan['execution']['mode'], 'execution mode mismatch');
assert_same('planned', $plan['execution']['state'], 'execution state mismatch');
assert_same('king://runs/moe-pretrain-run-2026-05-04/plan.iibin', $plan['execution']['run_object'], 'run object mismatch');
assert_same('king_pipeline_orchestrator', $plan['execution']['orchestrator']['runtime'], 'orchestrator runtime mismatch');
assert_same('king.training.distributed.agent_worker', $plan['execution']['orchestrator']['tool'], 'orchestrator tool mismatch');
assert_same('king_pipeline_orchestrator_dispatch', $plan['execution']['orchestrator']['submit_api'], 'orchestrator submit API mismatch');
assert_same('king_pipeline_orchestrator_worker_run_next', $plan['execution']['orchestrator']['worker_api'], 'orchestrator worker API mismatch');
assert_same('process_local_rebind_required', $plan['execution']['orchestrator']['handler_boundary'], 'orchestrator handler boundary mismatch');
assert_same('websocket', $plan['execution']['watch']['transport'], 'watch transport mismatch');
assert_same('iibin', $plan['execution']['watch']['frame_codec'], 'watch codec mismatch');
assert_same('KingTrainingRunEventV1', $plan['execution']['watch']['schema'], 'watch schema mismatch');
assert_same(65536, $plan['execution']['watch']['batch_limit'], 'watch batch limit mismatch');
assert_same('KingTrainingRunEnvelopeV1', $plan['execution']['envelope']['schema'], 'envelope schema mismatch');
assert_true(in_array('object_store', $plan['execution']['requires'], true), 'object_store requirement missing');
assert_true(in_array('iibin', $plan['execution']['requires'], true), 'iibin requirement missing');
assert_true(in_array('websocket', $plan['execution']['requires'], true), 'websocket requirement missing');
assert_true(in_array('pipeline_orchestrator', $plan['execution']['requires'], true), 'pipeline_orchestrator requirement missing');
assert_same('planned', $watch['status']['state'], 'watch state mismatch');
assert_same('training.run.planned', $watch['events'][0]['type'], 'watch event mismatch');
assert_same('websocket', $watch['transport']['kind'], 'watch transport kind mismatch');
assert_same('iibin', $watch['transport']['frame_codec'], 'watch frame codec mismatch');
assert_same('king_websocket_send', $watch['transport']['send_api'], 'watch send API mismatch');
assert_same('iibin', $envelope['codec'], 'envelope codec mismatch');
assert_same('KingTrainingRunEnvelopeV1', $envelope['schema'], 'envelope schema mismatch');
assert_same(1, $envelope['payload']['schema_version'], 'envelope schema version mismatch');
assert_same('moe-pretrain-run-2026-05-04', $envelope['payload']['run_id'], 'envelope run id mismatch');
assert_same('training-run!runs!moe-pretrain-run-2026-05-04!plan.iibin', $persistence['object_id'], 'persistence object id mismatch');
assert_same('king_object_store', $persistence['runtime'], 'persistence runtime mismatch');
assert_same('king_object_store_put', $persistence['write_api'], 'persistence write API mismatch');

$invalidUriRejected = false;
try {
    Training\Data::objectStore('file:///tmp/tokens')->format('iibin.tokens.v1');
} catch (InvalidArgumentException) {
    $invalidUriRejected = true;
}
assert_true($invalidUriRejected, 'invalid dataset URI was not rejected');

$invalidElasticRanksRejected = false;
try {
    FailurePolicy::training()->elasticRanks(min: 2048, max: 512);
} catch (InvalidArgumentException) {
    $invalidElasticRanksRejected = true;
}
assert_true($invalidElasticRanksRejected, 'invalid elastic rank bounds were not rejected');

echo "moe training dsl contract ok\n";
