<script setup>
	defineProps({
		locale: {
			type: String,
			required: true
		},
		label: {
			type: String,
			default: ''
		}
	})
</script>

<template>
	<span class="locale-flag" role="img" :aria-label="label || locale">
		<svg v-if="locale === 'he'" viewBox="0 0 30 20" aria-hidden="true">
			<rect width="30" height="20" fill="#fff" />
			<rect y="2" width="30" height="2" fill="#1746a2" />
			<rect y="16" width="30" height="2" fill="#1746a2" />
			<path d="M15 5.2 10.7 12.7h8.6L15 5.2Zm0 9.6 4.3-7.5h-8.6l4.3 7.5Z" fill="none" stroke="#1746a2" stroke-width="1.05" />
		</svg>
		<svg v-else-if="locale === 'en'" viewBox="0 0 30 20" aria-hidden="true">
			<rect width="30" height="20" fill="#fff" />
			<path d="M0 0h30v1.54H0Zm0 3.08h30v1.54H0Zm0 3.08h30V7.7H0Zm0 3.08h30v1.54H0Zm0 3.08h30v1.54H0Zm0 3.08h30v1.54H0Zm0 3.08h30V20H0Z" fill="#b22234" />
			<rect width="13" height="10.78" fill="#3c3b6e" />
			<g fill="#fff">
				<circle cx="2" cy="1.8" r="0.55" /><circle cx="5" cy="1.8" r="0.55" /><circle cx="8" cy="1.8" r="0.55" /><circle cx="11" cy="1.8" r="0.55" />
				<circle cx="3.5" cy="4.2" r="0.55" /><circle cx="6.5" cy="4.2" r="0.55" /><circle cx="9.5" cy="4.2" r="0.55" />
				<circle cx="2" cy="6.6" r="0.55" /><circle cx="5" cy="6.6" r="0.55" /><circle cx="8" cy="6.6" r="0.55" /><circle cx="11" cy="6.6" r="0.55" />
				<circle cx="3.5" cy="9" r="0.55" /><circle cx="6.5" cy="9" r="0.55" /><circle cx="9.5" cy="9" r="0.55" />
			</g>
		</svg>
		<svg v-else-if="locale === 'fr'" viewBox="0 0 30 20" aria-hidden="true">
			<rect width="10" height="20" fill="#1f3f8f" />
			<rect x="10" width="10" height="20" fill="#fff" />
			<rect x="20" width="10" height="20" fill="#e33a45" />
		</svg>
		<svg v-else viewBox="0 0 30 20" aria-hidden="true">
			<rect width="30" height="6.67" fill="#fff" />
			<rect y="6.67" width="30" height="6.67" fill="#2756b1" />
			<rect y="13.34" width="30" height="6.66" fill="#d52b3a" />
		</svg>
	</span>
</template>

<style scoped>
.locale-flag {
  display: inline-flex;
  flex: 0 0 auto;
  width: 28px;
  height: 19px;
  overflow: hidden;
  border: 1px solid rgba(17, 34, 45, 0.16);
  border-radius: 3px;
  background: #fff;
  box-shadow: 0 1px 3px rgba(17, 34, 45, 0.12);
}

.locale-flag svg {
  display: block;
  width: 100%;
  height: 100%;
}
</style>
