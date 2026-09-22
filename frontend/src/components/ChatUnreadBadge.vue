<script setup>
	import { computed } from 'vue'
	import { useI18n } from 'vue-i18n'

	const props = defineProps({ count: { type: [Number, String], default: 0 } })
	const { t } = useI18n()
	const unread = computed(() => Number.isFinite(Number(props.count)) ? Math.max(0, Math.floor(Number(props.count))) : 0)
</script>

<template>
	<span v-if="unread" class="chat-unread-badge" :aria-label="t('notifications.unread', { count: unread })" :title="t('notifications.unread', { count: unread })">
		{{ unread > 99 ? '99+' : unread }}
	</span>
</template>

<style lang="scss">
.chat-unread-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  min-width: 22px;
  height: 22px;
  padding: 0 6px;
  border-radius: 999px;
  background: var(--soz-primary, #7b3ff2);
  color: #fff;
  font-size: 12px;
  font-weight: 800;
  line-height: 1;
}

.chat-row--unread.chat-row--unread.chat-row--unread {
  position: relative;
  background: #fff1e8;
}

.chat-row--unread::before {
  position: absolute;
  inset-inline-start: 0;
  top: 8px;
  bottom: 8px;
  width: 3px;
  border-radius: 3px;
  background: var(--soz-orange, #ff7426);
  content: '';
}

.chat-row--unread strong {
  color: var(--soz-primary-deep, #42148f);
  font-weight: 900;
}

.chat-row--unread.chat-row--unread small {
  color: var(--soz-ink, #11222d);
  font-weight: 700;
}
</style>
