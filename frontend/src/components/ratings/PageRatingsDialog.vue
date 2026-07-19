<script setup>
	import { computed, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { fetchPageRatings } from '@/services/api/pages'
	import RatingStars from '@/components/ratings/RatingStars.vue'

	const props = defineProps({
		modelValue: {
			type: Boolean,
			default: false
		},
		pageId: {
			type: [Number, String],
			default: null
		}
	})

	const emit = defineEmits(['update:modelValue', 'loaded'])
	const { t } = useI18n()
	const loading = ref(false)
	const ratings = ref([])
	const summary = ref({ average: 0, count: 0 })
	const open = computed({
		get: () => props.modelValue,
		set: (value) => emit('update:modelValue', value)
	})
	const averageText = computed(() => Number(summary.value.average || 0).toFixed(1))

	async function loadRatings() {
		if (!props.pageId) {
			ratings.value = []
			summary.value = { average: 0, count: 0 }
			return
		}

		loading.value = true
		try {
			const { data } = await fetchPageRatings(props.pageId)
			ratings.value = data.data.items || []
			summary.value = data.data.summary || { average: 0, count: 0 }
			emit('loaded', summary.value)
		} finally {
			loading.value = false
		}
	}

	function formatDate(value) {
		return value ? new Date(value).toLocaleDateString() : ''
	}

	watch(open, (value) => {
		if (value) {
			loadRatings()
		}
	})
</script>

<template>
	<q-dialog v-model="open">
		<q-card class="ratings-dialog">
			<q-card-section class="ratings-dialog__head">
				<div>
					<div class="text-h6">{{ t('ratings.allRatings') }}</div>
					<div class="ratings-dialog__summary">
						<RatingStars readonly :value="summary.average" />
						<span v-if="summary.count > 0">
							{{ t('ratings.summary', { average: averageText, count: summary.count }) }}
						</span>
						<span v-else>{{ t('ratings.noRatings') }}</span>
					</div>
				</div>
				<q-btn flat round icon="close" color="dark" v-close-popup />
			</q-card-section>

			<q-card-section class="ratings-dialog__body">
				<div v-if="loading" class="row justify-center q-pa-lg">
					<q-spinner color="primary" />
				</div>
				<div v-else-if="ratings.length === 0" class="ratings-empty">
					{{ t('ratings.noRatings') }}
				</div>
				<div v-else class="ratings-list">
					<article v-for="rating in ratings" :key="rating.id" class="rating-item">
						<header class="rating-item__head">
							<div>
								<strong>{{ rating.user?.display_name }}</strong>
								<div class="text-caption text-grey-7">{{ formatDate(rating.updated_at || rating.created_at) }}</div>
							</div>
							<RatingStars readonly :value="rating.rating" />
						</header>
						<p v-if="rating.comment" class="rating-item__comment">{{ rating.comment }}</p>
					</article>
				</div>
			</q-card-section>
		</q-card>
	</q-dialog>
</template>

<style scoped lang="scss">
.ratings-dialog {
  width: min(760px, calc(100vw - 24px));
  max-width: 760px;
  max-height: calc(100vh - 32px);
  border-radius: 24px;
}

.ratings-dialog__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.ratings-dialog__summary {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  margin-top: 8px;
  color: rgba(17, 34, 45, 0.62);
}

.ratings-dialog__body {
  overflow-y: auto;
  max-height: min(560px, calc(100vh - 160px));
  padding-top: 0;
}

.ratings-list {
  display: grid;
  gap: 12px;
}

.rating-item {
  padding: 16px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.82);
}

.rating-item__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.rating-item__comment {
  margin: 12px 0 0;
  color: rgba(17, 34, 45, 0.72);
  line-height: 1.55;
  white-space: pre-line;
}

.ratings-empty {
  padding: 26px;
  color: rgba(17, 34, 45, 0.58);
  text-align: center;
}
</style>
