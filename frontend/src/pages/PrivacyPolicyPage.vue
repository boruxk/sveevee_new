<script setup>
	import { computed } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { getLegalDocument } from '@/constants/legalDocuments'

	const route = useRoute()
	const { t, locale } = useI18n()
	const policy = computed(() => getLegalDocument(route.meta.legalDocument || 'privacy', locale.value))
	const isPrivacyPolicy = computed(() => route.meta.legalDocument === 'privacy')
</script>

<template>
	<q-page padding class="privacy-page">
		<div class="privacy-shell">
			<section class="soz-section-card privacy-panel">
				<header class="privacy-head">
					<h1 class="soz-page-title">{{ policy.title }}</h1>
					<p class="privacy-updated">{{ policy.updated }}</p>
					<p class="privacy-intro">{{ policy.intro }}</p>
				</header>

				<aside v-if="isPrivacyPolicy" class="privacy-database-document">
					<div>
						<h2>{{ t('legal.databaseDocumentTitle') }}</h2>
						<p>{{ t('legal.databaseDocumentBody') }}</p>
					</div>
					<q-btn
						tag="a"
						href="/documents/sveevee-database-definition-he.pdf"
						target="_blank"
						rel="noopener"
						color="primary"
						unelevated
						rounded
						no-caps
					>
						<svg class="privacy-pdf-icon" viewBox="0 0 24 24" aria-hidden="true">
							<path d="M6.75 2.75h7.5l4 4v14.5H6.75z" />
							<path d="M14.25 2.75v4h4" />
							<path d="M9.25 11h6.5M9.25 14.25h6.5M9.25 17.5h4.25" />
						</svg>
						<span>{{ t('legal.databaseDocumentAction') }}</span>
					</q-btn>
				</aside>

				<div class="privacy-sections">
					<section v-for="section in policy.sections" :key="section.title" class="privacy-section">
						<h2>{{ section.title }}</h2>
						<p v-for="paragraph in section.body" :key="paragraph">{{ paragraph }}</p>
					</section>
				</div>
			</section>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.privacy-page {
  padding: 0 20px 36px;
}

.privacy-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.privacy-panel {
  padding: clamp(24px, 4vw, 42px);
}

.privacy-head {
  display: grid;
  gap: 14px;
}

.privacy-head h1,
.privacy-head p {
  margin: 0;
}

.privacy-updated {
  color: var(--soz-primary-deep);
  font-weight: 800;
}

.privacy-intro {
  max-width: 760px;
  color: rgba(17, 34, 45, 0.72);
  font-size: 1.08rem;
  line-height: 1.7;
}

.privacy-database-document {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  margin-top: 30px;
  padding: 20px 0;
  border-block: 1px solid rgba(17, 34, 45, 0.12);
}

.privacy-database-document h2,
.privacy-database-document p {
  margin: 0;
}

.privacy-database-document h2 {
  margin-bottom: 6px;
  font-size: 1.24rem;
}

.privacy-database-document p {
  color: rgba(17, 34, 45, 0.7);
  line-height: 1.55;
}

.privacy-database-document .q-btn {
  flex: 0 0 auto;
}

.privacy-pdf-icon {
  width: 20px;
  height: 20px;
  margin-inline-end: 7px;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.8;
}

.privacy-sections {
  display: grid;
  gap: 28px;
  margin-top: 34px;
}

.privacy-section {
  display: grid;
  gap: 10px;
}

.privacy-section h2 {
  margin: 0;
  font-size: clamp(1.35rem, 2vw, 1.8rem);
  line-height: 1.22;
}

.privacy-section p {
  margin: 0;
  color: rgba(17, 34, 45, 0.74);
  font-size: 1rem;
  line-height: 1.75;
}

@media (max-width: 700px) {
  .privacy-page {
    padding-inline: 10px;
  }

  .privacy-panel {
    padding: 22px;
  }

  .privacy-sections {
    gap: 24px;
    margin-top: 28px;
  }

  .privacy-database-document {
    align-items: stretch;
    flex-direction: column;
    gap: 16px;
  }

  .privacy-database-document .q-btn {
    align-self: flex-start;
  }
}
</style>
