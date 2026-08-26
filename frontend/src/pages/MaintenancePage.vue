<script setup>
	import { computed } from 'vue'
	import { useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { usePlatformStore } from '@/stores/platform'

	const { locale, t } = useI18n()
	const router = useRouter()
	const platformStore = usePlatformStore()
	const message = computed(() => platformStore.messageFor(locale.value) || t('maintenance.defaultMessage'))

	async function retry() {
		await platformStore.initialize(true)

		if (!platformStore.isMaintenance) {
			router.replace({ name: 'landing' })
		}
	}
</script>

<template>
	<q-page class="maintenance-page">
		<section class="maintenance-band" aria-live="polite">
			<span class="maintenance-icon"><q-icon name="construction" /></span>
			<p class="maintenance-kicker">Sveevee</p>
			<h1>{{ t('maintenance.title') }}</h1>
			<p class="maintenance-message">{{ message }}</p>
			<div class="maintenance-actions">
				<q-btn
					color="primary"
					unelevated
					rounded
					icon="refresh"
					:label="t('maintenance.retry')"
					:loading="platformStore.loading"
					@click="retry"
				/>
				<q-btn
					flat
					rounded
					icon="admin_panel_settings"
					:label="t('maintenance.adminLogin')"
					:to="{ name: 'login', query: { redirect: '/admin' } }"
				/>
			</div>
		</section>
	</q-page>
</template>

<style scoped lang="scss">
.maintenance-page {
  display: grid;
  min-height: calc(100vh - 180px);
  padding: 42px 20px 72px;
  place-items: center;
}

.maintenance-band {
  display: grid;
  width: min(720px, 100%);
  justify-items: center;
  padding: 48px 28px;
  border-block: 1px solid var(--soz-line);
  text-align: center;
}

.maintenance-icon {
  display: grid;
  width: 74px;
  height: 74px;
  margin-bottom: 18px;
  border-radius: 50%;
  background: var(--soz-menu-gradient);
  color: #fff;
  font-size: 34px;
  place-items: center;
}

.maintenance-kicker {
  margin: 0 0 6px;
  color: var(--soz-primary-deep);
  font-weight: 800;
}

.maintenance-band h1 {
  margin: 0;
  font-size: clamp(32px, 7vw, 58px);
  letter-spacing: 0;
  line-height: 1.05;
}

.maintenance-message {
  max-width: 620px;
  margin: 18px 0 0;
  color: var(--soz-muted);
  font-size: 18px;
  line-height: 1.6;
}

.maintenance-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: center;
  margin-top: 28px;
}

@media (max-width: 600px) {
  .maintenance-page {
    padding-inline: 12px;
  }

  .maintenance-band {
    padding-inline: 12px;
  }

  .maintenance-actions,
  .maintenance-actions .q-btn {
    width: 100%;
  }
}
</style>
