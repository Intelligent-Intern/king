import {
  appendMediaRuntimeTransitionEvent as appendMediaRuntimeTransitionEventImpl,
  readMediaRuntimeTransitionEvents as readMediaRuntimeTransitionEventsImpl,
} from './media/runtimeTelemetry.ts';

export function appendMediaRuntimeTransitionEvent(event = {}) {
  return appendMediaRuntimeTransitionEventImpl(event);
}

export function readMediaRuntimeTransitionEvents() {
  return readMediaRuntimeTransitionEventsImpl();
}
