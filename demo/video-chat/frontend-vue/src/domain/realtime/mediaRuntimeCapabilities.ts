import { detectMediaRuntimeCapabilities as detectMediaRuntimeCapabilitiesImpl } from './media/runtimeCapabilities.ts';

export async function detectMediaRuntimeCapabilities() {
  return detectMediaRuntimeCapabilitiesImpl();
}
