<script setup>
	import { reactive, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { createEvent } from '@/services/api/events'
	import { useRequiredFields } from '@/composables/useRequiredFields'

	const props = defineProps({
		pageId: {
			type: [Number, String],
			required: true
		}
	})

	const emit = defineEmits(['saved'])
	const { t } = useI18n()
	const $q = useQuasar()
	const loading = ref(false)
	const formRef = ref(null)
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)
	const form = reactive({
		name: '',
		description: '',
		image: null,
		date: '',
		time: '',
		address: ''
	})

	async function submit() {
		if (!(await validateRequiredForm(formRef))) {
			return
		}

		loading.value = true

		try {
			const { data } = await createEvent(props.pageId, form)
			form.name = ''
			form.description = ''
			form.image = null
			form.date = ''
			form.time = ''
			form.address = ''
			emit('saved', data.data)
			$q.notify({ type: 'positive', message: t('events.created') })
		} catch (error) {
			$q.notify({ type: 'negative', message: error.response?.data?.message || t('events.saveFailed') })
		} finally {
			loading.value = false
		}
	}
</script>

<template>
	<q-form ref="formRef" greedy class="event-composer" @submit.prevent="submit()">
		<q-input
			v-model="form.name"
			outlined
			:label="requiredLabel('events.name')"
			:rules="[requiredRule]"
		/>
		<q-input
			v-model="form.description"
			outlined
			type="textarea"
			autogrow
			:label="requiredLabel('events.description')"
			:rules="[requiredRule]"
		/>
		<q-file
			v-model="form.image"
			outlined
			clearable
			accept="image/*"
			:label="requiredLabel('events.image')"
			:rules="[requiredRule]"
		/>
		<div class="event-composer__row">
			<q-input
				v-model="form.date"
				outlined
				type="date"
				:label="requiredLabel('events.date')"
				:rules="[requiredRule]"
			/>
			<q-input
				v-model="form.time"
				outlined
				type="time"
				:label="requiredLabel('events.time')"
				:rules="[requiredRule]"
			/>
		</div>
		<q-input
			v-model="form.address"
			outlined
			:label="requiredLabel('events.address')"
			:rules="[requiredRule]"
		/>
		<div class="event-composer__actions">
			<q-btn
				rounded
				unelevated
				color="primary"
				type="submit"
				icon="event"
				:loading="loading"
				:label="t('actions.addEvent')"
			/>
		</div>
	</q-form>
</template>

<style scoped lang="scss">
.event-composer {
  display: grid;
  gap: 14px;
}

.event-composer__row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  align-items: start;
}

.event-composer__actions {
  display: flex;
  justify-content: flex-end;
  padding-top: 6px;
}

@media (max-width: 700px) {
  .event-composer__row {
    grid-template-columns: 1fr;
  }
}
</style>
