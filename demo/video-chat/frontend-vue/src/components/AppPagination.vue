<template>
  <div v-bind="rootAttrs" :class="rootClass">
    <button
      class="pager-btn pager-icon-btn"
      type="button"
      :disabled="!hasPrev || disabled"
      @click="emitPage(page - 1, 'prev')"
    >
      <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/backward.png" alt="" />
    </button>
    <div class="page-info">Page {{ page }} / {{ normalizedPageCount }} · {{ total }} {{ totalLabel }}</div>
    <button
      class="pager-btn pager-icon-btn"
      type="button"
      :disabled="!hasNext || disabled"
      @click="emitPage(page + 1, 'next')"
    >
      <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/forward.png" alt="" />
    </button>
  </div>
</template>

<script setup>
import { computed, useAttrs } from 'vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
  page: {
    type: Number,
    required: true,
  },
  pageCount: {
    type: Number,
    required: true,
  },
  total: {
    type: Number,
    default: 0,
  },
  totalLabel: {
    type: String,
    default: 'total',
  },
  hasPrev: {
    type: Boolean,
    default: false,
  },
  hasNext: {
    type: Boolean,
    default: false,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['page-change', 'prev', 'next']);
const attrs = useAttrs();
const normalizedPageCount = computed(() => Math.max(1, props.pageCount));
const rootAttrs = computed(() => {
  const { class: _class, ...rest } = attrs;
  return rest;
});
const rootClass = computed(() => ['pagination', attrs.class]);

function emitPage(nextPage, direction) {
  emit(direction, nextPage);
  emit('page-change', nextPage);
}
</script>
