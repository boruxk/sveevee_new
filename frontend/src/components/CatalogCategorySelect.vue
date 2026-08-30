<script setup>
	import { computed, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { catalogGroupsForScope, catalogLabel, catalogTopicByKey } from '@/constants/catalogTopics'

	const props = defineProps({
		modelValue: {
			type: String,
			default: ''
		},
		groups: {
			type: Array,
			default: () => []
		},
		scope: {
			type: [String, Array],
			required: true
		},
		required: {
			type: Boolean,
			default: false
		},
		label: {
			type: String,
			default: ''
		},
		disabled: {
			type: Boolean,
			default: false
		},
		name: {
			type: String,
			default: ''
		},
		error: {
			type: Boolean,
			default: false
		},
		errorMessage: {
			type: String,
			default: ''
		}
	})

	const emit = defineEmits(['update:modelValue'])
	const { t, locale } = useI18n()
	const menuOpen = ref(false)
	const selectedGroupKey = ref('')
	const scopedGroups = computed(() => catalogGroupsForScope(props.groups, props.scope))
	const selectedTopic = computed(() => catalogTopicByKey(props.groups, props.modelValue))
	const activeGroup = computed(() => scopedGroups.value.find((group) => group.key === selectedGroupKey.value) || null)
	const selectedTopicGroup = computed(() => scopedGroups.value.find((group) => group.key === selectedTopic.value?.group_key) || null)
	const selectedTopicOption = computed(() => {
		if (!selectedTopic.value || !selectedTopicGroup.value) {
			return null
		}

		return {
			label: catalogLabel(selectedTopic.value.labels, locale.value),
			value: selectedTopic.value.key,
			groupLabel: catalogLabel(selectedTopicGroup.value.labels, locale.value),
			color: selectedTopic.value.color || selectedTopicGroup.value.color,
			slug: selectedTopic.value.slug
		}
	})
	const fieldLabel = computed(() => props.label || (props.required ? `${t('catalog.category')} *` : t('catalog.category')))
	const requiredRule = (value) => !props.required || Boolean(value) || t('validation.required')
	const groupOptions = computed(() => scopedGroups.value.map((group) => ({
		label: catalogLabel(group.labels, locale.value),
		value: group.key,
		color: group.color,
		count: group.topics.length
	})))
	const topicOptions = computed(() => (activeGroup.value?.topics || []).map((topic) => ({
		label: catalogLabel(topic.labels, locale.value),
		value: topic.key,
		color: topic.color || activeGroup.value.color,
		slug: topic.slug
	})))

	function syncGroupFromValue() {
		if (!selectedTopic.value) {
			if (!scopedGroups.value.some((group) => group.key === selectedGroupKey.value)) {
				selectedGroupKey.value = ''
			}

			return
		}

		selectedGroupKey.value = selectedTopic.value.group_key
	}

	function ensureActiveGroup() {
		syncGroupFromValue()

		if (!selectedGroupKey.value && scopedGroups.value[0]) {
			selectedGroupKey.value = scopedGroups.value[0].key
		}
	}

	function openMenu() {
		if (props.disabled) {
			return
		}

		ensureActiveGroup()
		menuOpen.value = true
	}

	function selectGroup(value) {
		selectedGroupKey.value = value || ''
	}

	function selectTopic(option) {
		emit('update:modelValue', option?.value || '')
		menuOpen.value = false
	}

	function clearSelection() {
		emit('update:modelValue', '')
	}

	watch(() => [props.modelValue, props.groups, props.scope], syncGroupFromValue, { immediate: true })
</script>

<template>
	<div class="catalog-category-select">
		<q-field
			:model-value="modelValue"
			outlined
			class="catalog-category-select__field q-select"
			:label="fieldLabel"
			:disable="disabled"
			:name="name"
			:error="error"
			:error-message="errorMessage"
			:rules="required ? [requiredRule] : []"
		>
			<template #control>
				<button
					type="button"
					class="catalog-category-select__trigger"
					:disabled="disabled"
					:aria-label="fieldLabel"
					:aria-expanded="menuOpen"
					aria-haspopup="menu"
					@click="openMenu"
				>
					<span v-if="selectedTopicOption" class="catalog-category-select__value">
						<span class="catalog-category-select__dot" :style="{ backgroundColor: selectedTopicOption.color }" />
						<span class="catalog-category-select__group">{{ selectedTopicOption.groupLabel }}</span>
						<span class="catalog-category-select__separator">/</span>
						<span>{{ selectedTopicOption.label }}</span>
					</span>
				</button>
			</template>
			<template #append>
				<q-btn
					v-if="modelValue && !required"
					flat
					round
					dense
					size="sm"
					icon="close"
					:aria-label="t('actions.clear')"
					@click.stop="clearSelection"
				/>
				<q-icon name="expand_more" class="catalog-category-select__chevron" @click.stop="openMenu" />
				<q-menu
					v-model="menuOpen"
					anchor="bottom left"
					self="top left"
					class="catalog-category-menu"
					:offset="[0, 8]"
				>
					<div class="catalog-category-mega">
						<aside class="catalog-category-mega__groups">
							<button
								v-for="group in groupOptions"
								:key="group.value"
								type="button"
								class="catalog-category-mega__group"
								:class="{ 'catalog-category-mega__group--active': group.value === selectedGroupKey }"
								@click="selectGroup(group.value)"
							>
								<span class="catalog-category-select__dot" :style="{ backgroundColor: group.color }" />
								<span>{{ group.label }}</span>
								<small>{{ group.count }}</small>
							</button>
						</aside>
						<section class="catalog-category-mega__topics">
							<div v-if="activeGroup" class="catalog-category-mega__heading">
								<span class="catalog-category-select__dot" :style="{ backgroundColor: activeGroup.color }" />
								<strong>{{ catalogLabel(activeGroup.labels, locale) }}</strong>
							</div>
							<div v-if="topicOptions.length" class="catalog-category-mega__topic-grid">
								<button
									v-for="topic in topicOptions"
									:key="topic.value"
									type="button"
									class="catalog-category-mega__topic"
									:class="{ 'catalog-category-mega__topic--active': topic.value === modelValue }"
									:style="{ '--topic-color': topic.color }"
									@click="selectTopic(topic)"
								>
									<span class="catalog-category-select__dot" :style="{ backgroundColor: topic.color }" />
									<span>{{ topic.label }}</span>
								</button>
							</div>
							<div v-else class="catalog-category-mega__empty">
								{{ t('catalog.chooseMainCategory') }}
							</div>
						</section>
					</div>
				</q-menu>
			</template>
		</q-field>
	</div>
</template>

<style scoped lang="scss">
.catalog-category-select {
  display: block;
}

.catalog-category-select__field {
  width: 100%;
}

.catalog-category-select__field :deep(.q-field__native),
.catalog-category-select__field :deep(.q-field__control-container) {
  min-width: 0;
}

.catalog-category-select__field :deep(.q-field__control) {
  height: 50px;
  min-height: 50px;
  padding-inline-end: 8px;
}

.catalog-category-select__field :deep(.q-field__control-container) {
  display: flex;
  align-items: center;
  height: 50px;
  padding-inline-start: 5px;
}

.catalog-category-select__field :deep(.q-field__native) {
  display: flex;
  align-items: center;
  height: 50px;
  min-height: 50px;
  padding-top: 11px;
  padding-bottom: 11px;
  line-height: 1.2;
}

.catalog-category-select__trigger {
  display: flex;
  align-items: center;
  align-self: center;
  width: 100%;
  min-width: 0;
  height: 28px;
  min-height: 28px;
  overflow: hidden;
  padding: 0;
  border: 0;
  background: transparent;
  color: #152033;
  cursor: pointer;
  font: inherit;
  line-height: 1.2;
  text-align: inherit;
  white-space: nowrap;
}

.catalog-category-select__trigger:disabled {
  cursor: not-allowed;
}

.catalog-category-select__value {
  display: flex;
  min-width: 0;
  gap: 7px;
  align-items: center;
  width: 100%;
  overflow: hidden;
  line-height: 1.2;
  white-space: nowrap;
}

.catalog-category-select__value span:last-child,
.catalog-category-select__group {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.catalog-category-select__group,
.catalog-category-select__separator {
  color: rgba(17, 34, 45, 0.55);
}

.catalog-category-select__group,
.catalog-category-select__value span:last-child {
  min-width: 0;
}

.catalog-category-select__group {
  max-width: 42%;
}

.catalog-category-select__separator {
  flex: 0 0 auto;
}

.catalog-category-select__dot {
  flex: 0 0 auto;
  width: 11px;
  height: 11px;
  border-radius: 999px;
  box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.72);
}

.catalog-category-select__chevron {
  cursor: pointer;
}

:global(.catalog-category-menu) {
  max-height: min(78vh, 640px) !important;
  overflow: hidden;
  border-radius: 24px;
  box-shadow: 0 22px 56px rgba(21, 31, 59, 0.18);
}

.catalog-category-mega {
  display: grid;
  grid-template-columns: minmax(190px, 0.74fr) minmax(300px, 1.26fr);
  width: min(780px, calc(100vw - 24px));
  height: min(70vh, 580px);
  max-height: calc(100vh - 32px);
  overflow: hidden;
  background: #fff;
}

.catalog-category-mega__groups,
.catalog-category-mega__topics {
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-width: thin;
}

.catalog-category-mega__groups {
  display: grid;
  align-content: start;
  gap: 8px;
  padding: 12px;
  border-inline-end: 1px solid rgba(17, 34, 45, 0.08);
  background:
    linear-gradient(180deg, rgba(255, 116, 38, 0.08), rgba(245, 66, 145, 0.06)),
    rgba(255, 255, 255, 0.98);
}

.catalog-category-mega__group,
.catalog-category-mega__topic {
  min-width: 0;
  border: 0;
  cursor: pointer;
  font: inherit;
  text-align: inherit;
}

.catalog-category-mega__group {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: 9px;
  align-items: center;
  min-height: 42px;
  padding: 9px 10px;
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.66);
  color: rgba(17, 34, 45, 0.78);
  font-weight: 780;
}

.catalog-category-mega__group span:nth-child(2) {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.catalog-category-mega__group small {
  color: rgba(17, 34, 45, 0.45);
  font-size: 0.74rem;
  font-weight: 800;
}

.catalog-category-mega__group--active {
  background: var(--soz-action-gradient);
  color: #fff;
  box-shadow: 0 12px 24px rgba(245, 66, 145, 0.24);
}

.catalog-category-mega__group--active small {
  color: rgba(255, 255, 255, 0.78);
}

.catalog-category-mega__topics {
  padding: 16px;
}

.catalog-category-mega__heading {
  display: flex;
  gap: 9px;
  align-items: center;
  margin-bottom: 12px;
  color: #152033;
}

.catalog-category-mega__heading strong {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.catalog-category-mega__topic-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.catalog-category-mega__topic {
  display: flex;
  align-items: center;
  gap: 9px;
  min-height: 44px;
  padding: 10px 12px;
  border: 1px solid color-mix(in srgb, var(--topic-color, #f54291) 24%, rgba(17, 34, 45, 0.08));
  border-radius: 18px;
  background: color-mix(in srgb, var(--topic-color, #f54291) 8%, rgba(255, 255, 255, 0.94));
  color: #152033;
  font-weight: 760;
}

.catalog-category-mega__topic span:last-child {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.catalog-category-mega__topic--active {
  border-color: color-mix(in srgb, var(--topic-color, #f54291) 54%, rgba(17, 34, 45, 0.08));
  background: color-mix(in srgb, var(--topic-color, #f54291) 17%, #fff);
  box-shadow: 0 12px 28px color-mix(in srgb, var(--topic-color, #f54291) 18%, transparent);
}

.catalog-category-mega__empty {
  display: grid;
  min-height: 180px;
  place-items: center;
  color: rgba(17, 34, 45, 0.5);
  font-weight: 760;
}

@media (max-width: 700px) {
  .catalog-category-mega {
    grid-template-columns: 1fr;
    grid-template-rows: auto minmax(0, 1fr);
    width: min(360px, calc(100vw - 20px));
    height: min(72vh, 560px);
    max-height: calc(100vh - 24px);
  }

  .catalog-category-mega__groups {
    grid-auto-flow: column;
    grid-auto-columns: minmax(154px, 1fr);
    max-height: 116px;
    overflow-x: auto;
    overflow-y: hidden;
    border-inline-end: 0;
    border-bottom: 1px solid rgba(17, 34, 45, 0.08);
  }

  .catalog-category-mega__topic-grid {
    grid-template-columns: 1fr;
  }
}
</style>
