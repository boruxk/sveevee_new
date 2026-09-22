<script setup>
	import { computed, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useRoute } from 'vue-router'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { createCommunityReport } from '@/services/api/community'

	const props = defineProps({ modelValue: Boolean, targetType: { type: String, required: true }, targetId: { type: [Number, String], required: true } })
	const emit = defineEmits(['update:modelValue', 'reported'])
	const { t } = useI18n()
	const route = useRoute()
	const auth = useAuthStore()
	const $q = useQuasar()
	const reason = ref('')
	const saving = ref(false)
	const error = ref('')
	const open = computed({ get: () => props.modelValue, set: (value) => emit('update:modelValue', value) })
	watch(() => [props.targetType, props.targetId], () => { reason.value = ''; error.value = '' })

	async function submit() {
		if (!reason.value.trim() || saving.value) return
		saving.value = true
		error.value = ''
		try {
			await createCommunityReport(props.targetType, props.targetId, { reason: reason.value.trim() })
			reason.value = ''
			open.value = false
			emit('reported')
			$q.notify({ type: 'positive', message: t('community.reportSent') })
		} catch {
			error.value = t('community.actionFailed')
		} finally {
			saving.value = false
		}
	}
</script>

<template>
	<q-dialog v-model="open" :persistent="saving">
		<q-card class="community-report-dialog">
			<h2>{{ t('community.report') }}</h2>
			<q-form v-if="auth.isAuthenticated" @submit="submit">
				<q-input v-model="reason"
					outlined
					type="textarea"
					:label="t('community.reportReason')"
					maxlength="1000"
					:disable="saving"
				/>
				<p v-if="error" role="alert" class="text-negative">{{ error }}</p>
				<div class="report-actions">
					<q-btn flat :label="t('community.cancel')" :disable="saving" @click="open = false" />
					<q-btn type="submit" color="primary" :label="t('community.sendReport')" :loading="saving" :disable="!reason.trim()" />
				</div>
			</q-form>
			<q-btn v-else color="primary" :label="t('community.signIn')" :to="{ name: 'login', query: { redirect: route.fullPath } }" />
		</q-card>
	</q-dialog>
</template>

<style scoped>
.community-report-dialog { width: min(520px, calc(100vw - 24px)); max-width: 100%; padding: 24px; border-radius: 24px; }
.community-report-dialog h2 { margin: 0 0 18px; font-size: 24px; }
.report-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px; margin-top: 18px; }
</style>
