import { detectMediaRuntimeCapabilities as detectMediaRuntimeCapabilitiesImpl } from './media/runtimeCapabilities.js';

export async function detectMediaRuntimeCapabilities() {
  return detectMediaRuntimeCapabilitiesImpl();
}
