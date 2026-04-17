# @intelligentintern/iibin

IIBIN JavaScript/TypeScript protocol library used by King realtime clients.

## Install

```bash
npm install @intelligentintern/iibin
# or
yarn add @intelligentintern/iibin
```

## Usage

```ts
import { IIBINEncoder, IIBINDecoder, MessageType } from '@intelligentintern/iibin'

const encoder = new IIBINEncoder()
const decoder = new IIBINDecoder()

const encoded = encoder.encode({
  type: MessageType.TEXT_MESSAGE,
  data: { text: 'hello' }
})

const decoded = decoder.decode(encoded)
console.log(decoded)
```

## Build

```bash
npm run build
```

