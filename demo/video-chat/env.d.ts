/// <reference types="vite/client" />

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<{}, {}, any>
  export default component
}

// King IIBIN Protocol types
declare global {
  interface Window {
    kingExtension?: {
      version: string
      iibin: {
        connect: (url: string) => Promise<IIBINConnection>
        createServer: (port: number) => Promise<IIBINServer>
      }
    }
  }
}

export interface IIBINConnection {
  send: (data: ArrayBuffer | Uint8Array) => Promise<void>
  close: () => void
  onMessage: (callback: (data: ArrayBuffer) => void) => void
  onClose: (callback: () => void) => void
  onError: (callback: (error: Error) => void) => void
}

export interface IIBINServer {
  listen: () => Promise<void>
  close: () => void
  onConnection: (callback: (connection: IIBINConnection) => void) => void
}

export {}