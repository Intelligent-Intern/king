<template>
  <section class="settings-view">
    <header class="settings-header">
      <h2>Connection Settings</h2>
      <p>Tune transport, media, and session defaults before joining traffic.</p>
    </header>

    <div class="settings-grid">
      <article class="settings-card">
        <h3>Realtime Transport</h3>
        <ul>
          <li>Prefer binary IIBIN payloads for chat and control frames.</li>
          <li>Keep reconnect attempts bounded to avoid retry storms.</li>
          <li>Use short heartbeat intervals only on trusted low-latency links.</li>
        </ul>
      </article>

      <article class="settings-card">
        <h3>Media Defaults</h3>
        <ul>
          <li>Start muted when joining shared rooms.</li>
          <li>Prefer camera preview checks before placing outbound calls.</li>
          <li>Gate screen-share behind explicit user interaction.</li>
        </ul>
      </article>

      <article class="settings-card">
        <h3>Operational Notes</h3>
        <ul>
          <li>Wire real persistence behind King session storage for production.</li>
          <li>Bind telemetry export before enabling autoscale reactions.</li>
          <li>Run the demo behind TLS when WebRTC is exposed publicly.</li>
        </ul>
      </article>
    </div>
  </section>
</template>

<script setup lang="ts">
</script>

<style scoped>
.settings-view {
  padding: 2rem;
  color: #e5e7eb;
}

.settings-header {
  margin-bottom: 1.5rem;
}

.settings-header h2 {
  margin: 0 0 0.5rem;
}

.settings-header p {
  margin: 0;
  color: #94a3b8;
}

.settings-grid {
  display: grid;
  gap: 1rem;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}

.settings-card {
  padding: 1.25rem;
  border: 1px solid rgba(148, 163, 184, 0.25);
  border-radius: 1rem;
  background: rgba(15, 23, 42, 0.8);
}

.settings-card h3 {
  margin-top: 0;
}

.settings-card ul {
  margin: 0;
  padding-left: 1.25rem;
  color: #cbd5e1;
}
</style>
