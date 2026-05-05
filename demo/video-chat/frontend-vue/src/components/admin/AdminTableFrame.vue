<template>
  <section class="table-wrap admin-table-frame">
    <slot />
  </section>
</template>

<style scoped>
.admin-table-frame {
  flex: 1 1 auto;
  min-height: 0;
  margin-top: 0;
  padding-left: 10px;
  padding-right: 10px;
}
</style>
