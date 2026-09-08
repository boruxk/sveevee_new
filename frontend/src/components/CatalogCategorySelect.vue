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
			:error="error ? true : null"
			:error-message="error ? errorMessage : undefined"
			:rules="required ? [requiredRule] : undefined"
		>
			<template #control="{ id }">
				<button
					:id="id"
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
