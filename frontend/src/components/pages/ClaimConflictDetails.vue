<script setup>
	import { computed } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { catalogLabel } from '@/constants/catalogTopics'
	import { presencePalettes } from '@/constants/presencePalettes'
	import { locationLabel } from '@/utils/locationLabels'
	import { pageClaimComparison } from '@/utils/pageClaimComparison'

	const props = defineProps({
		claim: { type: Object, required: true },
		topics: { type: Array, default: () => [] },
		pendingGroupCount: { type: Number, default: 0 }
	})
	const { t, te, locale } = useI18n()
	const personName = (person) => person?.display_name || person?.name || (person?.id ? `#${person.id}` : '—')
	const matchedFields = computed(() => (props.claim.matched_on || []).map((field) => (
		te(`admin.claimMatchedFields.${field}`) ? t(`admin.claimMatchedFields.${field}`) : field
	)))
	const comparison = computed(() => pageClaimComparison(props.claim, {
		t,
		categoryLabel: (key) => catalogLabel(props.topics.find((topic) => topic.key === key)?.labels, locale.value) || key || '',
		cityLabel: (value) => locationLabel(value, 'city', locale.value) || value || '',
		paletteLabel: (key) => {
			const palette = presencePalettes.find((item) => item.key === key)
			return palette ? t(palette.nameKey) : key || ''
		}
	}))
</script>

<template>
	<section class="claim-conflict-details" :aria-label="t('admin.claimConflict')">
		<p v-if="pendingGroupCount > 1">{{ t('admin.claimMultipleMatches', { count: pendingGroupCount }) }}</p>
		<dl class="claim-conflict-people">
			<div>
				<dt>{{ t('admin.claimCurrentOwner') }}</dt>
				<dd>
					<strong>{{ claim.current_owner ? personName(claim.current_owner) : t('admin.pages.unclaimed') }}</strong>
					<bdi v-if="claim.current_owner?.email">{{ claim.current_owner.email }}</bdi>
				</dd>
			</div>
			<div>
				<dt>{{ t('admin.claimRequester') }}</dt>
				<dd>
					<strong>{{ personName(claim.requester) }}</strong>
					<bdi v-if="claim.requester?.email">{{ claim.requester.email }}</bdi>
				</dd>
			</div>
		</dl>
		<p v-if="matchedFields.length"><strong>{{ t('admin.claimMatchedOn') }}:</strong> {{ matchedFields.join(', ') }}</p>
		<details v-if="comparison.length" class="claim-conflict-comparison">
			<summary>{{ t('admin.claimCompareData') }}</summary>
			<div class="claim-conflict-table" tabindex="0" role="region" :aria-label="t('admin.claimCompareData')">
				<table>
					<caption class="sr-only">{{ t('admin.claimCompareData') }}</caption>
					<thead>
						<tr>
							<th scope="col">{{ t('admin.claimDataField') }}</th>
							<th scope="col">{{ t('admin.claimCurrentData') }}</th>
							<th scope="col">{{ t('admin.claimProposedData') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in comparison" :key="row.key" :class="{ 'claim-conflict-changed': row.changed }">
							<th scope="row">
								{{ t(row.labelKey) }}
								<small v-if="row.changed">{{ t('admin.claimDataChanged') }}</small>
							</th>
							<td>
								<img v-if="row.image && row.before" :src="row.before" :alt="`${t(row.labelKey)} · ${t('admin.claimCurrentData')}`" loading="lazy" />
								<span v-else dir="auto">{{ row.before || '—' }}</span>
							</td>
							<td>
								<img v-if="row.image && row.after" :src="row.after" :alt="`${t(row.labelKey)} · ${t('admin.claimProposedData')}`" loading="lazy" />
								<span v-else dir="auto">{{ row.after || '—' }}</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</details>
	</section>
</template>

<style scoped lang="scss">
.claim-conflict-details { display: grid; gap: 12px; min-width: 0; }
.claim-conflict-people { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin: 0; }
.claim-conflict-people dt { color: var(--soz-muted); font-size: 0.8rem; }
.claim-conflict-people dd { display: grid; gap: 2px; margin: 2px 0 0; overflow-wrap: anywhere; }
.claim-conflict-details p { margin: 0; }
.claim-conflict-comparison summary { cursor: pointer; font-weight: 700; padding: 6px 0; }
.claim-conflict-table { overflow-x: auto; margin-top: 6px; }
.claim-conflict-table table { width: 100%; min-width: 420px; border-collapse: collapse; font-size: 0.85rem; }
.claim-conflict-table th, .claim-conflict-table td { padding: 9px; border: 1px solid var(--soz-border, #ddd); text-align: start; vertical-align: top; white-space: pre-line; overflow-wrap: anywhere; }
.claim-conflict-table th { font-weight: 650; }
.claim-conflict-table td { width: 36%; }
.claim-conflict-table small { display: block; margin-top: 4px; color: var(--q-primary); }
.claim-conflict-changed td:last-child { background: rgba(123, 63, 242, 0.07); }
.claim-conflict-table img { display: block; max-width: 120px; max-height: 90px; object-fit: contain; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
@media (max-width: 480px) { .claim-conflict-people { grid-template-columns: 1fr; } }
</style>
