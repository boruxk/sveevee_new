<script setup>
	import { computed, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import ResponsiveImage from '@/components/ResponsiveImage.vue'

	const props = defineProps({
		service: {
			type: Object,
			required: true
		},
		editable: {
			type: Boolean,
			default: false
		},
		palette: {
			type: Object,
			default: null
		}
	})

	const emit = defineEmits(['delete', 'edit'])
	const { t } = useI18n()
	const $q = useQuasar()
	const detailOpen = ref(false)
	const compactActionButtons = computed(() => $q.screen.width <= 700)
	const serviceLink = computed(() => String(props.service.link || '').trim())
	const serviceImageAlt = computed(() => props.service?.image_alt || props.service?.name || '')
	const serviceImageSizes = computed(() => props.service?.image_sizes || '(max-width: 760px) calc(100vw - 36px), 360px')
	const themeStyle = computed(() => {
		if (!props.palette) {
			return null
		}

		return {
			'--presence-accent': props.palette.accent,
			'--presence-surface': props.palette.surface,
			'--presence-card': props.palette.card || 'rgba(255, 255, 255, 0.82)',
			'--presence-border': props.palette.border || 'rgba(17, 34, 45, 0.1)',
			'--presence-ink': props.palette.ink || '#151f2d',
			'--presence-muted': props.palette.muted || 'rgba(17, 34, 45, 0.72)'
		}
	})
</script>

<template>
	<article class="service-card" :style="themeStyle">
		<ResponsiveImage
			v-if="service.image_url"
			class="service-card__image"
			:src="service.image_url"
			:alt="serviceImageAlt"
			:avif-srcset="service.image_avif_srcset || ''"
			:webp-srcset="service.image_webp_srcset || ''"
			:sizes="serviceImageSizes"
			:width="service.image_width || 768"
			:height="service.image_height || 576"
		/>
		<div class="service-card__body">
			<div class="service-card__copy">
				<h3 class="service-card__title">{{ service.name }}</h3>
				<p class="service-card__description">{{ service.description }}</p>
			</div>
			<div class="service-card__actions">
				<q-btn
					class="service-card__view-btn"
					:round="compactActionButtons"
					:rounded="!compactActionButtons"
					unelevated
					color="primary"
					icon="visibility"
					:aria-label="t('businessServices.open')"
					:label="compactActionButtons ? undefined : t('businessServices.open')"
					@click="detailOpen = true"
				/>
				<q-btn v-if="editable"
					class="service-card__icon-btn"
					round
					unelevated
					color="secondary"
					icon="edit"
					:aria-label="t('actions.edit')"
					@click="emit('edit', service)"
				>
					<q-tooltip>{{ t('actions.edit') }}</q-tooltip>
				</q-btn>
				<q-btn v-if="editable"
					class="service-card__icon-btn"
					round
					unelevated
					color="negative"
					icon="delete"
					:aria-label="t('actions.delete')"
					@click="emit('delete', service)"
				>
					<q-tooltip>{{ t('actions.delete') }}</q-tooltip>
				</q-btn>
			</div>
		</div>
	</article>
	<q-dialog v-model="detailOpen">
		<q-card class="service-detail-dialog" :style="themeStyle">
			<ResponsiveImage
				v-if="service.image_url"
				class="service-detail-dialog__image"
				:src="service.image_url"
				:alt="serviceImageAlt"
				:avif-srcset="service.image_avif_srcset || ''"
				:webp-srcset="service.image_webp_srcset || ''"
				:sizes="serviceImageSizes"
				:width="service.image_width || 768"
				:height="service.image_height || 576"
			/>
			<q-card-section class="service-detail-dialog__body">
				<div class="service-detail-dialog__head">
					<h3>{{ service.name }}</h3>
					<q-btn flat round icon="close" class="service-detail-dialog__close" v-close-popup />
				</div>
				<p class="service-detail-dialog__description">{{ service.description }}</p>
				<div v-if="serviceLink" class="service-detail-dialog__actions">
					<q-btn
						rounded
						unelevated
						color="primary"
						icon="open_in_new"
						:href="serviceLink"
						target="_blank"
						rel="noopener noreferrer"
						:label="t('businessServices.visit')"
					/>
				</div>
			</q-card-section>
		</q-card>
	</q-dialog>
</template>

<style scoped lang="scss">
.service-card {
  display: grid;
  grid-template-columns: minmax(220px, 32%) minmax(0, 1fr);
  min-width: 0;
  overflow: hidden;
  border: 1px solid var(--presence-border, rgba(17, 34, 45, 0.1));
  border-radius: 8px;
  background: var(--presence-card, rgba(255, 255, 255, 0.78));
  color: var(--presence-ink, #151f2d);
}

.service-card__image {
  min-height: 230px;
  --responsive-image-fit: cover;
  --responsive-image-position: center;
}

.service-card__body {
  display: grid;
  gap: 18px;
  min-width: 0;
  padding: 20px;
}

.service-card__copy {
  min-width: 0;
}

.service-card__title {
  margin: 0 0 8px;
  color: var(--presence-ink, #151f2d);
  font-size: 23px;
  line-height: 1.25;
  overflow-wrap: anywhere;
}

.service-card__description {
  display: -webkit-box;
  overflow: hidden;
  margin: 0;
  color: var(--presence-muted, rgba(17, 34, 45, 0.72));
  line-height: 1.6;
  white-space: pre-line;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 5;
}

.service-card__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 10px;
  align-self: end;
}

.service-card__icon-btn {
  aspect-ratio: 1;
  width: 53px;
  min-width: 53px;
  height: 53px;
  min-height: 53px;
  padding: 0;
}

.service-detail-dialog {
  overflow: hidden;
  width: min(760px, calc(100vw - 24px));
  max-width: 760px;
  max-height: calc(100vh - 32px);
  border: 1px solid color-mix(in srgb, var(--presence-accent, #f97316) 28%, var(--presence-border, rgba(17, 34, 45, 0.1)));
  border-radius: 24px;
  background:
    radial-gradient(circle at top left, color-mix(in srgb, var(--presence-accent, #f97316) 16%, transparent), transparent 42%),
    color-mix(in srgb, var(--presence-surface, #fffaf6) 88%, var(--presence-accent, #f97316) 12%);
  color: var(--presence-ink, #151f2d);
  box-shadow: 0 24px 58px color-mix(in srgb, var(--presence-accent, #f97316) 18%, transparent);
}

.service-detail-dialog__image {
  min-height: 280px;
  --responsive-image-fit: cover;
  --responsive-image-position: center;
}

.service-detail-dialog__body {
  display: grid;
  gap: 18px;
}

.service-detail-dialog__head {
  display: flex;
  gap: 16px;
  align-items: flex-start;
  justify-content: space-between;
}

.service-detail-dialog__head h3 {
  margin: 0;
  color: var(--presence-ink, #151f2d);
  font-size: 28px;
  line-height: 1.2;
  overflow-wrap: anywhere;
}

.service-detail-dialog__description {
  overflow-y: auto;
  max-height: 34vh;
  margin: 0;
  color: var(--presence-muted, rgba(17, 34, 45, 0.76));
  line-height: 1.65;
  white-space: pre-line;
}

.service-detail-dialog__actions {
  display: flex;
  justify-content: flex-end;
}

.service-detail-dialog__close {
  color: var(--presence-ink, #151f2d) !important;
}

.service-detail-dialog__actions .q-btn.bg-primary {
  background: var(--presence-accent, var(--soz-action-gradient)) !important;
  box-shadow: 0 14px 28px color-mix(in srgb, var(--presence-accent, #f97316) 22%, transparent) !important;
}

@media (max-width: 760px) {
  .service-card {
    grid-template-columns: 1fr;
  }

  .service-card__image {
    min-height: 180px;
  }

  .service-card__body {
    padding: 16px;
  }

  .service-card__actions {
    justify-content: flex-start;
  }

  .service-card__view-btn,
  .service-card__icon-btn {
    aspect-ratio: 1;
    width: 53px;
    min-width: 53px;
    height: 53px;
    min-height: 53px;
    padding: 0;
  }

  .service-detail-dialog {
    width: calc(100vw - 20px);
    max-height: calc(100dvh - 20px);
    border-radius: 20px;
  }

  .service-detail-dialog__image {
    min-height: 190px;
  }

  .service-detail-dialog__body {
    padding: 18px;
  }

  .service-detail-dialog__head h3 {
    font-size: 24px;
  }

  .service-detail-dialog__actions .q-btn {
    width: 100%;
  }
}
</style>
