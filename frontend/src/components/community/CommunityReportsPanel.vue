<script setup>
	import { onBeforeUnmount, onMounted, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { fetchCommunityReports, updateCommunityReport } from '@/services/api/community'

	const { t, locale } = useI18n()
	const items = ref([])
	const page = ref(1)
	const lastPage = ref(1)
	const loading = ref(false)
	const error = ref('')
	const busy = ref({})
	let generation = 0

	function dateTime(value) {
		const date = new Date(value)
		return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(date)
	}

	function targetPath(report) {
		const path = String(report.target?.public_path || '')
		return path.startsWith('/') && !path.startsWith('//') ? path : null
	}

	async function load(nextPage = page.value) {
		const request = ++generation
		loading.value = true
		error.value = ''
		try {
			const { data } = await fetchCommunityReports({ page: nextPage, per_page: 20 })
			if (request !== generation) return
			items.value = data.data?.items || []
			page.value = data.data?.pagination?.current_page || 1
			lastPage.value = data.data?.pagination?.last_page || 1
		} catch {
			if (request === generation) error.value = t('community.reportsFailed')
		} finally {
			if (request === generation) loading.value = false
		}
	}

	async function review(report, action) {
		if (busy.value[report.id]) return
		busy.value[report.id] = true
		error.value = ''
		try {
			await updateCommunityReport(report.id, { action })
			await load()
		} catch {
			error.value = t('community.actionFailed')
		} finally {
			busy.value[report.id] = false
		}
	}

	onMounted(() => load())
	onBeforeUnmount(() => { generation++ })
</script>

<template>
	<section class="community-reports">
		<header><h2>{{ t('community.moderation') }}</h2><q-btn flat :label="t('community.refresh')" :loading="loading" @click="load()" /></header>
		<p v-if="error" role="alert" class="text-negative">{{ error }}</p>
		<p v-if="!loading && !items.length">{{ t('community.noReports') }}</p>
		<article v-for="report in items" :key="report.id" class="community-report">
			<div class="report-heading"><strong>{{ report.target?.title || t('community.reportedContent') }}</strong><q-badge :color="report.status === 'pending' ? 'warning' : 'grey-6'">{{ t(`community.reportStatus.${report.status}`) }}</q-badge></div>
			<p class="report-meta">{{ report.reporter?.display_name || t('community.member') }} · {{ dateTime(report.created_at) }}</p>
			<p class="report-body" dir="auto">{{ report.target?.body }}</p>
			<p class="report-reason" dir="auto"><strong>{{ t('community.reportReason') }}: </strong>{{ report.reason }}</p>
			<footer>
				<q-btn v-if="targetPath(report)" flat color="primary" :label="t('community.viewContent')" :to="targetPath(report)" />
				<q-btn v-if="report.status === 'pending'"
					outline
					color="primary"
					:label="t('community.dismissReport')"
					:loading="busy[report.id]"
					@click="review(report, 'dismiss')"
				/>
				<q-btn v-if="report.status === 'pending' && !report.target?.hidden" color="negative" :label="t('community.hideContent')" :loading="busy[report.id]" @click="review(report, 'hide')" />
			</footer>
		</article>
		<div v-if="lastPage > 1" class="reports-pagination"><q-btn flat :label="t('community.previous')" :disable="page <= 1 || loading" @click="load(page - 1)" /><span>{{ page }} / {{ lastPage }}</span><q-btn flat :label="t('community.next')" :disable="page >= lastPage || loading" @click="load(page + 1)" /></div>
	</section>
</template>

<style scoped>
.community-reports { display: grid; gap: 16px; min-width: 0; }
.community-reports > header, .report-heading, .community-report footer, .reports-pagination { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.community-reports > header { justify-content: space-between; }
.community-reports h2 { font-size: 24px; margin: 0; }
.community-report { padding: 20px; background: rgba(255,255,255,.8); border: 1px solid var(--soz-line); border-radius: 22px; min-width: 0; }
.report-heading { justify-content: space-between; }
.report-meta { color: var(--soz-muted); font-size: 13px; }
.report-body, .report-reason { white-space: pre-wrap; overflow-wrap: anywhere; }
.report-body { max-height: 180px; overflow: auto; }
.reports-pagination { justify-content: center; }
</style>
