import { computed, type Ref } from 'vue';
import { t } from '../../../localization/i18nRuntime.js';

type RawRecord = Record<string, unknown>;

export type GossipNetworkNodeKind = 'room' | 'peer';
export type GossipNetworkHealth = 'healthy' | 'degraded' | 'idle' | 'unknown';

export interface GossipNetworkNode {
  id: string;
  label: string;
  kind: GossipNetworkNodeKind;
  health: GossipNetworkHealth;
  x: number;
  y: number;
  neighborCount: number;
  sent: number;
  received: number;
  repairs: number;
  detail: string;
}

export interface GossipNetworkEdge {
  id: string;
  from: string;
  to: string;
  health: GossipNetworkHealth;
  x1: number;
  y1: number;
  x2: number;
  y2: number;
}

export interface GossipNetworkMap {
  callKey: string;
  callId: string;
  roomId: string;
  title: string;
  lifecycle: string;
  nodes: GossipNetworkNode[];
  edges: GossipNetworkEdge[];
  analysis: string[];
  selectedNode: GossipNetworkNode | null;
  summary: {
    nodeCount: number;
    edgeCount: number;
    healthyCount: number;
    degradedCount: number;
    statusLabel: string;
    statusTagClass: string;
  };
}

function asRecord(value: unknown): RawRecord {
  return value && typeof value === 'object' && !Array.isArray(value) ? value as RawRecord : {};
}

function asArray(value: unknown): unknown[] {
  return Array.isArray(value) ? value : [];
}

function cleanString(value: unknown): string {
  return typeof value === 'string' || typeof value === 'number' ? String(value).trim() : '';
}

function nonNegativeInt(value: unknown): number {
  const parsed = Number.parseInt(String(value ?? '').trim(), 10);
  return Number.isFinite(parsed) ? Math.max(0, parsed) : 0;
}

function firstString(...values: unknown[]): string {
  for (const value of values) {
    const normalized = cleanString(value);
    if (normalized !== '') return normalized;
  }
  return '';
}

function healthFrom(value: unknown, fallback: GossipNetworkHealth = 'unknown'): GossipNetworkHealth {
  const normalized = cleanString(value).toLowerCase();
  if (['ok', 'healthy', 'live', 'running', 'connected', 'ready'].includes(normalized)) return 'healthy';
  if (['warning', 'warn', 'degraded', 'error', 'lost', 'closed', 'failed', 'blocked'].includes(normalized)) return 'degraded';
  if (['idle', 'observing', 'shadow', 'sfu_first', 'sfu_first_explicit'].includes(normalized)) return 'idle';
  return fallback;
}

function healthTagClass(health: GossipNetworkHealth): string {
  return health === 'healthy' ? 'ok' : 'warn';
}

function compactLabel(value: string, fallback: string): string {
  const label = value.trim() || fallback;
  return label.length > 24 ? `${label.slice(0, 21)}...` : label;
}

export function gossipNetworkMapCallKey(callValue: unknown): string {
  const call = asRecord(callValue);
  const roomId = firstString(call.room_id, call.roomId);
  const callId = firstString(call.id, call.call_id, roomId);
  return `${callId || 'call'}::${roomId || 'room'}`;
}

function telemetryCounters(peerTelemetry: RawRecord): RawRecord {
  const counters = asRecord(peerTelemetry.counters);
  return Object.keys(counters).length > 0 ? counters : peerTelemetry;
}

function buildDetail(node: GossipNetworkNode): string {
  if (node.kind === 'room') {
    return t('users.overview.gossip_room_detail', { neighbors: node.neighborCount });
  }
  return t('users.overview.gossip_peer_detail', {
    neighbors: node.neighborCount,
    sent: node.sent,
    received: node.received,
    repairs: node.repairs,
  });
}

function addNode(nodes: Map<string, GossipNetworkNode>, node: Omit<GossipNetworkNode, 'detail'>): void {
  const existing = nodes.get(node.id);
  if (!existing) {
    const next = { ...node, detail: '' };
    next.detail = buildDetail(next);
    nodes.set(next.id, next);
    return;
  }

  existing.neighborCount = Math.max(existing.neighborCount, node.neighborCount);
  existing.sent += node.sent;
  existing.received += node.received;
  existing.repairs += node.repairs;
  if (existing.health !== 'degraded') existing.health = node.health;
  existing.detail = buildDetail(existing);
}

function addEdge(edges: Map<string, Omit<GossipNetworkEdge, 'x1' | 'y1' | 'x2' | 'y2'>>, from: string, to: string, health: GossipNetworkHealth): void {
  if (from === '' || to === '' || from === to) return;
  const [left, right] = [from, to].sort();
  const id = `${left}--${right}`;
  if (edges.has(id)) return;
  edges.set(id, { id, from, to, health });
}

function peerNodeId(roomId: string, peerId: string): string {
  return `${roomId || 'room'}:${peerId}`;
}

function normalizeNeighborId(neighbor: unknown): string {
  const record = asRecord(neighbor);
  return firstString(record.peer_id, record.peerId, record.id, neighbor);
}

function topologyPeers(topology: RawRecord): string[] {
  const admitted = asArray(topology.admitted_peers)
    .map((peer) => firstString(asRecord(peer).peer_id, asRecord(peer).id, peer))
    .filter(Boolean);
  const candidates = asArray(topology.transport_candidates)
    .map((peer) => firstString(asRecord(peer).peer_id, asRecord(peer).id, peer))
    .filter(Boolean);
  return Array.from(new Set([...admitted, ...candidates]));
}

function positionNodes(nodes: GossipNetworkNode[]): GossipNetworkNode[] {
  const roomNodes = nodes.filter((node) => node.kind === 'room');
  const peerNodes = nodes.filter((node) => node.kind === 'peer');
  const centerX = 50;
  const centerY = 50;

  roomNodes.forEach((node, index) => {
    node.x = roomNodes.length <= 1 ? centerX : 32 + (36 * index) / Math.max(1, roomNodes.length - 1);
    node.y = roomNodes.length <= 1 ? centerY : 52;
  });

  peerNodes.forEach((node, index) => {
    const angle = (-Math.PI / 2) + ((Math.PI * 2 * index) / Math.max(1, peerNodes.length));
    const radiusX = peerNodes.length > 6 ? 43 : 38;
    const radiusY = peerNodes.length > 6 ? 34 : 30;
    node.x = centerX + Math.cos(angle) * radiusX;
    node.y = centerY + Math.sin(angle) * radiusY;
  });

  return [...roomNodes, ...peerNodes];
}

function positionEdges(
  edges: Array<Omit<GossipNetworkEdge, 'x1' | 'y1' | 'x2' | 'y2'>>,
  nodes: GossipNetworkNode[],
): GossipNetworkEdge[] {
  const byId = new Map(nodes.map((node) => [node.id, node]));
  return edges
    .map((edge) => {
      const from = byId.get(edge.from);
      const to = byId.get(edge.to);
      if (!from || !to) return null;
      return {
        ...edge,
        x1: from.x,
        y1: from.y,
        x2: to.x,
        y2: to.y,
      };
    })
    .filter((edge): edge is GossipNetworkEdge => edge !== null);
}

function buildAnalysis({
  nodeCount,
  edgeCount,
  healthyCount,
  degradedCount,
  aggregateTelemetry,
}: {
  nodeCount: number;
  edgeCount: number;
  healthyCount: number;
  degradedCount: number;
  aggregateTelemetry: RawRecord;
}): string[] {
  if (nodeCount <= 0) {
    return [t('users.overview.gossip_analysis_waiting')];
  }

  const totals = asRecord(aggregateTelemetry.totals || aggregateTelemetry.counters);
  const sent = nonNegativeInt(totals.sent);
  const received = nonNegativeInt(totals.received);
  const forwarded = nonNegativeInt(totals.forwarded);
  const repairs = nonNegativeInt(totals.topology_repairs_requested);
  const lines = [
    t('users.overview.gossip_analysis_topology', { nodes: nodeCount, edges: edgeCount }),
    t('users.overview.gossip_analysis_health', { healthy: healthyCount, degraded: degradedCount }),
  ];
  if (sent > 0 || received > 0 || forwarded > 0) {
    lines.push(t('users.overview.gossip_analysis_traffic', { sent, received, forwarded }));
  }
  if (repairs > 0) {
    lines.push(t('users.overview.gossip_analysis_repairs', { repairs }));
  }
  return lines;
}

function buildGossipNetworkMapForCall(
  callValue: unknown,
  aggregateTelemetry: RawRecord,
  selectedNodeId: string,
): GossipNetworkMap {
    const nodes = new Map<string, GossipNetworkNode>();
    const edges = new Map<string, Omit<GossipNetworkEdge, 'x1' | 'y1' | 'x2' | 'y2'>>();
    const call = asRecord(callValue);
    const roomId = firstString(call.room_id, call.roomId, call.id);
    const callId = firstString(call.id, call.call_id, roomId);
    const callKey = gossipNetworkMapCallKey(call);
    const title = compactLabel(firstString(call.title, callId), t('users.overview.untitled_call'));
    const lifecycle = firstString(asRecord(call.gossip).lifecycle, call.status, 'running');

    if (roomId !== '') {
      const topology = asRecord(call.gossip_topology || asRecord(call.gossip).topology);
      const topologyByPeer = asRecord(call.gossip_topology_by_peer_id);
      const roomTelemetry = asRecord(asRecord(aggregateTelemetry.rooms)[roomId] || asRecord(aggregateTelemetry)[roomId] || call.gossip_telemetry);
      const peerTelemetry = asRecord(roomTelemetry.peers);
      const liveParticipants = nonNegativeInt(asRecord(call.live_participants).total);
      const sfuPublishers = nonNegativeInt(asRecord(call.sfu).publishers);
      const roomHealth = healthFrom(asRecord(roomTelemetry.rollout_gate).decision, liveParticipants > 0 ? 'idle' : 'unknown');

      addNode(nodes, {
        id: roomId,
        label: title,
        kind: 'room',
        health: roomHealth,
        x: 50,
        y: 50,
        neighborCount: Object.keys(topologyByPeer).length || asArray(topology.assigned_neighbors).length,
        sent: sfuPublishers,
        received: liveParticipants,
        repairs: nonNegativeInt(asRecord(roomTelemetry.totals).topology_repairs_requested),
      });

      const peerIds = new Set<string>([
        ...topologyPeers(topology),
        ...Object.keys(topologyByPeer),
        ...Object.keys(peerTelemetry),
      ]);
      const localPeerId = firstString(topology.peer_id, topology.viewer_peer_id, topology.local_peer_id);
      if (localPeerId !== '') peerIds.add(localPeerId);

      for (const peerId of peerIds) {
        const telemetry = asRecord(peerTelemetry[peerId]);
        const counters = telemetryCounters(telemetry);
        const assignedNeighbors = asArray(asRecord(topologyByPeer[peerId]).neighbors);
        const localNeighbors = peerId === localPeerId ? asArray(topology.assigned_neighbors || topology.neighbors) : [];
        const neighborCount = assignedNeighbors.length + localNeighbors.length || nonNegativeInt(telemetry.neighbor_count);
        const nodeId = peerNodeId(roomId, peerId);
        const peerHealth = healthFrom(
          firstString(telemetry.carrier_state, telemetry.health, asRecord(roomTelemetry.rollout_gate).decision),
          neighborCount > 0 ? 'healthy' : 'idle',
        );

        addNode(nodes, {
          id: nodeId,
          label: compactLabel(firstString(telemetry.label, telemetry.display_name, peerId), peerId),
          kind: 'peer',
          health: peerHealth,
          x: 50,
          y: 50,
          neighborCount,
          sent: nonNegativeInt(counters.sent),
          received: nonNegativeInt(counters.received),
          repairs: nonNegativeInt(counters.topology_repairs_requested),
        });

        for (const neighbor of [...assignedNeighbors, ...localNeighbors]) {
          const neighborId = normalizeNeighborId(neighbor);
          if (neighborId === '') continue;
          const neighborNodeId = peerNodeId(roomId, neighborId);
          if (!nodes.has(neighborNodeId)) {
            addNode(nodes, {
              id: neighborNodeId,
              label: compactLabel(neighborId, neighborId),
              kind: 'peer',
              health: 'idle',
              x: 50,
              y: 50,
              neighborCount: 0,
              sent: 0,
              received: 0,
              repairs: 0,
            });
          }
          addEdge(edges, nodeId, neighborNodeId, peerHealth);
        }
      }
    }

    const positionedNodes = positionNodes(Array.from(nodes.values()));
    const positionedEdges = positionEdges(Array.from(edges.values()), positionedNodes);
    const selectedNode = positionedNodes.find((node) => node.id === selectedNodeId) || positionedNodes[0] || null;
    const healthyCount = positionedNodes.filter((node) => node.health === 'healthy').length;
    const degradedCount = positionedNodes.filter((node) => node.health === 'degraded').length;
    const statusLabel = positionedNodes.length === 0
      ? t('users.overview.gossip_status_waiting')
      : degradedCount > 0
        ? t('users.overview.gossip_status_degraded')
        : healthyCount > 0
          ? t('users.overview.gossip_status_healthy')
          : t('users.overview.gossip_status_observing');

    return {
      callKey,
      callId,
      roomId,
      title,
      lifecycle,
      nodes: positionedNodes,
      edges: positionedEdges,
      analysis: buildAnalysis({
        nodeCount: positionedNodes.length,
        edgeCount: positionedEdges.length,
        healthyCount,
        degradedCount,
        aggregateTelemetry: asRecord(asRecord(aggregateTelemetry.rooms)[roomId] || asRecord(aggregateTelemetry)[roomId] || call.gossip_telemetry),
      }),
      selectedNode,
      summary: {
        nodeCount: positionedNodes.length,
        edgeCount: positionedEdges.length,
        healthyCount,
        degradedCount,
        statusLabel,
        statusTagClass: healthTagClass(degradedCount > 0 ? 'degraded' : healthyCount > 0 ? 'healthy' : 'idle'),
      },
    };
}

export function useGossipNetworkMaps(
  operationsState: RawRecord,
  selectedNodeIdsByCall: RawRecord,
) {
  return computed<GossipNetworkMap[]>(() => {
    const aggregateTelemetry = asRecord(operationsState.gossipTelemetry);
    return asArray(operationsState.runningCalls)
      .map((callValue) => {
        const callKey = gossipNetworkMapCallKey(callValue);
        const selectedNodeId = cleanString(selectedNodeIdsByCall[callKey]);
        return buildGossipNetworkMapForCall(callValue, aggregateTelemetry, selectedNodeId);
      })
      .filter((map) => map.callId !== '' || map.roomId !== '');
  });
}

export function useGossipNetworkMap(
  operationsState: RawRecord,
  selectedNodeId: Ref<string>,
) {
  return computed<GossipNetworkMap>(() => {
    const maps = asArray(operationsState.runningCalls);
    const firstMap = buildGossipNetworkMapForCall(
      maps[0] || {},
      asRecord(operationsState.gossipTelemetry),
      selectedNodeId.value,
    );
    return firstMap;
  });
}
