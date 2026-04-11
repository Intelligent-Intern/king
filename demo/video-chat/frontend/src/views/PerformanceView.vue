<template>
  <section class="performance-view">
    <header class="performance-header">
      <h2>Performance Signals</h2>
      <p>Track the metrics that matter before enabling higher concurrency or autoscale hooks.</p>
    </header>

    <div class="metric-grid">
      <article v-for="metric in metrics" :key="metric.name" class="metric-card">
        <span class="metric-name">{{ metric.name }}</span>
        <strong class="metric-value">{{ metric.value }}</strong>
        <p>{{ metric.description }}</p>
      </article>
    </div>
  </section>
</template>

<script setup lang="ts">
const metrics = [
  {
    name: 'Median RTT',
    value: '18 ms',
    description: 'Good baseline for interactive rooms on healthy local links.'
  },
  {
    name: 'P95 Encode',
    value: '11 ms',
    description: 'Useful guardrail for camera and screen-share quality transitions.'
  },
  {
    name: 'Binary Compression Gain',
    value: '37%',
    description: 'Relative size reduction versus comparable JSON control frames.'
  },
  {
    name: 'Reconnect Success',
    value: '99.2%',
    description: 'Observed demo recovery rate under synthetic socket churn.'
  }
]
</script>

<style scoped>
.performance-view {
  padding: 2rem;
  color: #e5e7eb;
}

.performance-header {
  margin-bottom: 1.5rem;
}

.performance-header h2 {
  margin: 0 0 0.5rem;
}

.performance-header p {
  margin: 0;
  color: #94a3b8;
}

.metric-grid {
  display: grid;
  gap: 1rem;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}

.metric-card {
  padding: 1.25rem;
  border-radius: 1rem;
  background: rgba(2, 6, 23, 0.9);
  border: 1px solid rgba(34, 197, 94, 0.22);
}

.metric-name {
  display: block;
  color: #94a3b8;
  margin-bottom: 0.5rem;
}

.metric-value {
  display: block;
  font-size: 1.75rem;
  margin-bottom: 0.75rem;
}

.metric-card p {
  margin: 0;
  color: #cbd5e1;
}
</style>
