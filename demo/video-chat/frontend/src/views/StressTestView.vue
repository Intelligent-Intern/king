<template>
  <section class="stress-view">
    <header class="stress-header">
      <h2>Stress Test Profiles</h2>
      <p>Use repeatable client profiles to probe transport saturation and UI resilience.</p>
    </header>

    <div class="stress-grid">
      <article v-for="profile in profiles" :key="profile.name" class="stress-card">
        <h3>{{ profile.name }}</h3>
        <p>{{ profile.summary }}</p>
        <dl>
          <div>
            <dt>Messages/s</dt>
            <dd>{{ profile.messagesPerSecond }}</dd>
          </div>
          <div>
            <dt>Payload</dt>
            <dd>{{ profile.payload }}</dd>
          </div>
          <div>
            <dt>Duration</dt>
            <dd>{{ profile.duration }}</dd>
          </div>
        </dl>
      </article>
    </div>
  </section>
</template>

<script setup lang="ts">
const profiles = [
  {
    name: 'Burst Chat',
    summary: 'Short spikes to validate queue pressure and backoff handling.',
    messagesPerSecond: 250,
    payload: '512 B',
    duration: '30 s'
  },
  {
    name: 'Room Flood',
    summary: 'Sustained fan-out profile for busy public channels.',
    messagesPerSecond: 1200,
    payload: '1 KB',
    duration: '2 min'
  },
  {
    name: 'Binary Attachments',
    summary: 'Mixed text and binary frames for IIBIN transport validation.',
    messagesPerSecond: 180,
    payload: '32 KB',
    duration: '45 s'
  }
]
</script>

<style scoped>
.stress-view {
  padding: 2rem;
  color: #e5e7eb;
}

.stress-header {
  margin-bottom: 1.5rem;
}

.stress-header h2 {
  margin: 0 0 0.5rem;
}

.stress-header p {
  margin: 0;
  color: #94a3b8;
}

.stress-grid {
  display: grid;
  gap: 1rem;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}

.stress-card {
  padding: 1.25rem;
  border-radius: 1rem;
  background: linear-gradient(160deg, rgba(15, 23, 42, 0.95), rgba(30, 41, 59, 0.8));
  border: 1px solid rgba(56, 189, 248, 0.22);
}

.stress-card h3 {
  margin-top: 0;
}

.stress-card p {
  color: #cbd5e1;
}

.stress-card dl {
  margin: 0;
  display: grid;
  gap: 0.5rem;
}

.stress-card dt {
  font-size: 0.85rem;
  color: #94a3b8;
}

.stress-card dd {
  margin: 0;
  font-weight: 600;
}
</style>
