import {
  appendMediaRuntimeTransitionEvent as appendMediaRuntimeTransitionEventImpl,
  readMediaRuntimeTransitionEvents as readMediaRuntimeTransitionEventsImpl,
} from './media/runtimeTelemetry.js';

export function appendMediaRuntimeTransitionEvent(event = {}) {
  return appendMediaRuntimeTransitionEventImpl(event);
}

export function readMediaRuntimeTransitionEvents() {
  return readMediaRuntimeTransitionEventsImpl();
}
