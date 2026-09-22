<template>
	<span class="community-badge"><slot /></span>
</template>

<style scoped>
.community-badge { display: inline-flex; align-items: center; max-width: 100%; min-height: 30px; padding: 4px 11px; border: 1px solid var(--soz-line); border-radius: 999px; background: var(--soz-primary-tint); color: var(--soz-primary-deep); font-size: 13px; font-weight: 800; line-height: 1.3; overflow-wrap: anywhere; box-shadow: 0 8px 18px rgba(123, 63, 242, .12); }
</style>
