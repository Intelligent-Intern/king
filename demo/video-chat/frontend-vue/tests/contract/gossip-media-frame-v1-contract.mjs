import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const repoRoot = path.resolve(__dirname, '../../../../..')
const contractPath = path.join(repoRoot, 'demo/video-chat/contracts/v1/gossip-media-frame.contract.json')

function fail(message) {
  throw new Error(`[gossip-media-frame-v1-contract] FAIL: ${message}`)
}

function readContract() {
  return JSON.parse(fs.readFileSync(contractPath, 'utf8'))
}

function requiredField(contract, fieldName) {
  const field = contract.required_fields?.[fieldName]
  assert.ok(field, `required field missing: ${fieldName}`)
  return field
}

function assertEnum(field, expected, label) {
  assert.deepEqual(field.enum, expected, `${label} enum mismatch`)
}

function collectKeys(value, keys = []) {
  if (Array.isArray(value)) {
    for (const item of value) collectKeys(item, keys)
    return keys
  }
  if (!value || typeof value !== 'object') return keys
  for (const [key, nested] of Object.entries(value)) {
    keys.push(key)
    collectKeys(nested, keys)
  }
  return keys
}

try {
  const contract = readContract()

  assert.equal(contract.contract_name, 'king-video-chat-gossip-media-frame')
  assert.equal(contract.contract_version, 'v1.0.0')
  assert.equal(contract.wire_message_type, 'gossip.media.frame.v1')
  assert.equal(contract.external_envelope?.message_type, 'gossip.media.frame.v1')
  assert.equal(contract.external_envelope?.allow_additional_fields, false)
  assert.ok(
    contract.external_envelope?.forbidden_message_types?.includes('sfu/frame'),
    'external Gossip media envelope must explicitly forbid the SFU frame wire name',
  )

  assertEnum(requiredField(contract, 'type'), ['gossip.media.frame.v1'], 'type')
  assertEnum(requiredField(contract, 'frame_kind'), ['keyframe', 'delta'], 'frame_kind')
  assert.equal(requiredField(contract, 'sequence').type, 'u64')
  assert.deepEqual(requiredField(contract, 'sequence').monotonic_scope, [
    'call_id',
    'room_id',
    'participant_session_id',
    'track_id',
  ])
  assert.equal(requiredField(contract, 'participant_session_id').min_length, 8)
  assert.equal(requiredField(contract, 'call_id').min_length, 1)
  assert.equal(requiredField(contract, 'room_id').min_length, 1)
  assert.equal(requiredField(contract, 'timestamp_unix_ms').type, 'u64')

  assertEnum(requiredField(contract, 'codec_id'), ['wlvc_v1', 'webcodecs_vp8', 'webcodecs_vp9', 'webcodecs_av1'], 'codec_id')
  assertEnum(requiredField(contract, 'runtime_path'), ['gossip_rtc_datachannel', 'gossip_primary_direct'], 'runtime_path')
  assert.equal(requiredField(contract, 'profile').enum?.[0], 'video_720p30')
  assert.equal(contract.profile?.max_width, 1280)
  assert.equal(contract.profile?.max_height, 720)
  assert.equal(contract.profile?.max_fps, 30)

  assert.equal(contract.continuity?.first_frame_per_track, 'keyframe')
  assert.equal(contract.continuity?.delta_without_prior_keyframe, 'reject_and_request_keyframe')
  assert.equal(contract.continuity?.duplicate_sequence, 'drop_duplicate')
  assert.equal(contract.publication_gate?.ops_lane_authority?.server_head_authoritative, true)
  assert.equal(contract.publication_gate?.ops_lane_authority?.client_health_checks, false)
  assert.equal(contract.publication_gate?.ops_lane_authority?.client_topology_repair_requests, false)
  assert.equal(contract.publication_gate?.ops_lane_authority?.client_recovery_requests, false)
  assert.equal(contract.publication_gate?.ops_lane_authority?.before_sequence_allocation, false)
  assert.equal(
    contract.publication_gate?.ops_lane_authority?.publish_decision,
    'send_when_existing_call_socket_or_assigned_datachannel_accepts_frame',
  )
  assert.deepEqual(contract.publication_gate?.ops_lane_authority?.allowed_egress, ['open_websocket', 'open_rtc_datachannel'])
  assert.deepEqual(
    contract.publication_gate?.ops_lane_authority?.forbidden_client_behaviors,
    ['health_gate', 'topology_repair_request', 'missing_frame_recovery_request', 'sfu_fallback', 'media_security_fallback', 'reconnect_loop'],
  )

  const forbiddenFields = new Set(contract.redaction?.forbidden_fields || [])
  for (const field of ['sdp', 'ice_candidate', 'token', 'secret', 'private_key', 'raw_media_key']) {
    assert.ok(forbiddenFields.has(field), `redaction list must forbid ${field}`)
  }
  assert.match(contract.redaction?.forbidden_field_pattern || '', /sdp/)
  assert.match(contract.redaction?.forbidden_field_pattern || '', /ice/)
  assert.match(contract.redaction?.forbidden_field_pattern || '', /token/)
  assert.match(contract.redaction?.forbidden_field_pattern || '', /secret/)

  assert.equal(contract.compatibility?.first_sprint_decoder_adapter_allowed, true)
  assert.equal(contract.compatibility?.adapter_internal_target, 'existing_remote_frame_decoder')
  assert.equal(contract.compatibility?.adapter_may_use_sfu_frame_shape_in_memory, true)
  assert.equal(contract.compatibility?.adapter_must_not_emit_external_sfu_frame_envelope, true)
  assert.equal(contract.compatibility?.external_envelope_must_remain, 'gossip.media.frame.v1')

  const sampleTypes = new Set((contract.sample_vectors || []).map((vector) => vector.frame?.type))
  assert.deepEqual(sampleTypes, new Set(['gossip.media.frame.v1']))
  const sampleKinds = new Set((contract.sample_vectors || []).map((vector) => vector.frame?.frame_kind))
  assert.ok(sampleKinds.has('keyframe'), 'samples must include a keyframe')
  assert.ok(sampleKinds.has('delta'), 'samples must include a delta frame')

  const negativeNames = new Set((contract.negative_test_vectors || []).map((vector) => vector.name))
  assert.ok(negativeNames.has('external-envelope-named-sfu-frame'), 'negative vectors must reject sfu/frame as an external envelope')
  assert.ok(negativeNames.has('redacted-sdp-field'), 'negative vectors must reject SDP metadata')
  assert.ok(negativeNames.has('redacted-ice-token-field'), 'negative vectors must reject ICE/token metadata')

  const allKeys = collectKeys(contract)
  const externalSfuTypeLeaks = allKeys.filter((key) => key === 'sfu_frame' || key === 'sfuFrame')
  assert.deepEqual(externalSfuTypeLeaks, [], 'contract keys must not rename Gossip media frames as SFU frames')

  process.stdout.write('[gossip-media-frame-v1-contract] PASS\n')
} catch (error) {
  if (error instanceof Error) {
    fail(error.message)
  }
  fail('unknown failure')
}
