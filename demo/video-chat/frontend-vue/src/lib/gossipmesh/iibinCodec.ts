import { IIBINDecoder, IIBINEncoder, MessageType } from '../../../../../../packages/iibin/dist/index.js'
import type { GossipFrameMessage } from './gossipController'

export const GOSSIP_IIBIN_ENVELOPE_CONTRACT = 'king-video-chat-gossipmesh-iibin-media-envelope'
export const GOSSIP_IIBIN_CODEC_ID = 'iibin'
export const GOSSIP_NATIVE_OBJECT_STORE_CONTRACT = 'king-object-store-gossipmesh-control-plane'
export const GOSSIP_NATIVE_TRANSPORT_STACK = Object.freeze([
  'rtc_datachannel',
  'king_lsquic_http3',
  'king_websocket_binary',
])

export interface GossipDataPlaneCodec {
  readonly codecId: typeof GOSSIP_IIBIN_CODEC_ID
  encode(msg: GossipFrameMessage): ArrayBuffer
  decode(data: ArrayBuffer): GossipFrameMessage
}

export class GossipIibinCodec implements GossipDataPlaneCodec {
  readonly codecId = GOSSIP_IIBIN_CODEC_ID

  encode(msg: GossipFrameMessage): ArrayBuffer {
    return new IIBINEncoder().encode({
      type: MessageType.VIDEO_MESSAGE,
      id: String(msg?.frame_id || msg?.frameId || msg?.route_id || ''),
      timestamp: Number(msg?.timestamp || Date.now()),
      data: msg,
      metadata: {
        contract: GOSSIP_IIBIN_ENVELOPE_CONTRACT,
        codec_id: GOSSIP_IIBIN_CODEC_ID,
        native_object_store_contract: GOSSIP_NATIVE_OBJECT_STORE_CONTRACT,
        transport_stack: GOSSIP_NATIVE_TRANSPORT_STACK,
      },
    })
  }

  decode(data: ArrayBuffer): GossipFrameMessage {
    const decoded = new IIBINDecoder(data).decode()
    if (decoded?.metadata?.contract !== GOSSIP_IIBIN_ENVELOPE_CONTRACT) {
      throw new Error('gossip_iibin_contract_mismatch')
    }
    if (decoded?.metadata?.codec_id !== GOSSIP_IIBIN_CODEC_ID) {
      throw new Error('gossip_iibin_codec_mismatch')
    }
    return decoded.data as GossipFrameMessage
  }
}

export const GOSSIP_IIBIN_CODEC = new GossipIibinCodec()
