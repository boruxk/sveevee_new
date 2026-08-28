<script setup>
	import { computed, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { fetchPageRatings, savePageRating } from '@/services/api/pages'
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

	const emit = defineEmits(['update:modelValue', 'saved'])
	const { t } = useI18n()
	const $q = useQuasar()
	const loading = ref(false)
	const saving = ref(false)
	const rating = ref(0)
	const comment = ref('')
	const open = computed({
		get: () => props.modelValue,
		set: (value) => emit('update:modelValue', value)
	})

	async function loadMine() {
		if (!props.pageId) {
			return
		}

		loading.value = true
		try {
			const { data } = await fetchPageRatings(props.pageId)
			const myRating = data.data.my_rating
			rating.value = myRating?.rating || 0
			comment.value = myRating?.comment || ''
		} finally {
			loading.value = false
		}
	}

	async function save() {
		if (!rating.value) {
			$q.notify({ type: 'warning', message: t('ratings.chooseRating') })
			return
		}

		saving.value = true
		try {
			const { data } = await savePageRating(props.pageId, {
				rating: rating.value,
				comment: comment.value
			})
			emit('saved', data.data)
			$q.notify({ type: 'positive', message: t('ratings.saved') })
			open.value = false
		} catch {
			$q.notify({ type: 'negative', message: t('ratings.saveFailed') })
		} finally {
			saving.value = false
		}
	}

	watch(open, (value) => {
		if (value) {
			loadMine()
		}
	})
</script>

<template>
	<q-dialog v-model="open">
		<q-card class="review-dialog">
			<q-card-section class="review-dialog__head">
				<div>
					<div class="text-h6">{{ t('ratings.ratePage') }}</div>
					<div class="text-body2 text-grey-7">{{ t('ratings.editHint') }}</div>
				</div>
				<q-btn flat round icon="close" color="dark" v-close-popup />
			</q-card-section>

			<q-card-section class="review-dialog__body">
				<div v-if="loading" class="row justify-center q-pa-lg">
					<q-spinner color="primary" />
				</div>
				<q-form v-else class="review-form" @submit.prevent="save">
					<div>
						<div class="review-form__label">{{ t('ratings.yourRating') }}</div>
						<RatingStars v-model="rating" size="32px" />
					</div>
					<q-input
						v-model="comment"
						outlined
						type="textarea"
						autogrow
						:label="t('ratings.comment')"
					/>
					<div class="row justify-end">
						<q-btn rounded
							unelevated
							color="primary"
							type="submit"
							icon="save"
							:loading="saving"
							:label="t('ratings.save')"
						/>
					</div>
				</q-form>
			</q-card-section>
		</q-card>
	</q-dialog>
</template>

<style scoped lang="scss">
.review-dialog {
  width: min(620px, calc(100vw - 24px));
  max-width: 620px;
  border-radius: 24px;
}

.review-dialog__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.review-dialog__body {
  padding-top: 0;
}

.review-form {
  display: grid;
  gap: 18px;
}

.review-form__label {
  margin-bottom: 8px;
  color: rgba(17, 34, 45, 0.62);
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

@media (max-width: 640px) {
  .review-dialog {
    width: calc(100vw - 20px);
    border-radius: 20px;
  }

  .review-dialog__head {
    align-items: flex-start;
  }

  .review-form .q-btn {
    width: 100%;
  }
}
</style>
