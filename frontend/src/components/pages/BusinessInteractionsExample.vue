<script setup>
	import { computed, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { matNotificationsActive, matQuestionAnswer, matAddCircleOutline, matFavorite, matFavoriteBorder } from '@quasar/extras/material-icons'
	import CommunityBadge from '@/components/community/CommunityBadge.vue'
	import { businessExampleInteractions } from '@/constants/businessExampleInteractions'

	const props = defineProps({
		pageName: { type: String, required: true },
		productName: { type: String, required: true },
		serviceName: { type: String, required: true },
		adTitle: { type: String, required: true },
		following: { type: Boolean, default: false }
	})
	const { locale, t } = useI18n()
	const copy = computed(() => businessExampleInteractions[locale.value] || businessExampleInteractions.en)
	const activeItem = ref('product')
	const likedReplies = ref({ product: false, service: false })
	const itemName = computed(() => activeItem.value === 'product' ? props.productName : props.serviceName)
	const question = computed(() => activeItem.value === 'product' ? copy.value.productQuestion : copy.value.serviceQuestion)
	const reply = computed(() => activeItem.value === 'product' ? copy.value.productReply : copy.value.serviceReply)
	const liked = computed(() => likedReplies.value[activeItem.value])
</script>

<template>
	<section class="business-interactions-example" :dir="locale === 'he' ? 'rtl' : 'ltr'">
		<header class="business-interactions-example__heading">
			<span class="business-interactions-example__eyebrow">{{ copy.demoLabel }}</span>
			<h2>{{ copy.title }}</h2>
			<p>{{ copy.demoHint }}</p>
		</header>
		<div class="business-interactions-example__grid">
			<article class="interaction-card">
				<h3><q-icon :name="matNotificationsActive" aria-hidden="true" />{{ t('community.following') }}</h3>
				<p>{{ copy.followHint }}</p>
				<div class="interaction-sample">
					<div class="interaction-card__status" aria-live="polite">
						<q-icon :name="following ? 'check_circle' : matAddCircleOutline" aria-hidden="true" />
						<strong>{{ t(following ? 'community.following' : 'community.follow') }}</strong>
					</div>
					<span>{{ pageName }}</span>
					<small>{{ following ? copy.followPreviewOn : copy.followPreviewOff }}</small>
				</div>
				<div class="interaction-sample interaction-sample--notification">
					<strong>{{ copy.newAd }}</strong>
					<span>{{ adTitle }}</span>
				</div>
				<div class="interaction-card__footer">
					<strong>{{ t('community.subscriptions') }}</strong>
					<p>{{ copy.subscriptionsHint }}</p>
				</div>
			</article>

			<article class="interaction-card">
				<h3><q-icon :name="matQuestionAnswer" aria-hidden="true" />{{ t('community.questions') }}</h3>
				<p>{{ copy.questionsHint }}</p>
				<div class="interaction-card__selector" role="group" :aria-label="t('community.questions')">
					<button type="button" :aria-pressed="activeItem === 'product'" @click="activeItem = 'product'">{{ copy.product }}</button>
					<button type="button" :aria-pressed="activeItem === 'service'" @click="activeItem = 'service'">{{ copy.service }}</button>
				</div>
				<div class="interaction-card__thread" aria-live="polite">
					<strong class="interaction-card__item">{{ itemName }}</strong>
					<div class="interaction-sample">
						<strong>{{ t('community.question') }}</strong>
						<p>{{ question }}</p>
					</div>
					<div class="interaction-sample interaction-sample--reply">
						<div class="interaction-card__author">
							<strong>{{ pageName }}</strong>
							<CommunityBadge>{{ t('community.ownerReply') }}</CommunityBadge>
						</div>
						<p>{{ reply }}</p>
						<button
							type="button"
							class="interaction-card__like"
							:class="{ 'interaction-card__like--active': liked }"
							:aria-pressed="liked"
							:aria-label="t(liked ? 'community.unlike' : 'community.like')"
							@click="likedReplies[activeItem] = !liked"
						>
							<q-icon :name="liked ? matFavorite : matFavoriteBorder" aria-hidden="true" />
							<span>{{ t(liked ? 'community.liked' : 'community.like') }}</span>
							<span>{{ liked ? 3 : 2 }}</span>
						</button>
					</div>
				</div>
			</article>

			<article class="interaction-card">
				<h3><q-icon name="place" aria-hidden="true" />{{ t('community.nearby') }}</h3>
				<p>{{ copy.nearbyHint }}</p>
				<div class="interaction-sample">
					<strong>{{ t('community.question') }}</strong>
					<p>{{ copy.nearbyQuestion }}</p>
				</div>
				<div class="interaction-sample interaction-sample--reply">
					<strong>{{ t('community.reply') }}</strong>
					<p>{{ copy.nearbyReply }}</p>
					<a class="interaction-card__recommendation" href="#business-example-banner">
						<q-icon name="storefront" aria-hidden="true" />
						<span><small>{{ t('community.recommendedBusiness') }}</small><strong>{{ pageName }}</strong></span>
					</a>
				</div>
				<router-link class="interaction-card__nearby" to="/nearby">{{ copy.exploreNearby }}</router-link>
			</article>
		</div>
	</section>
</template>

<style scoped>
.business-interactions-example { display: grid; gap: 20px; min-width: 0; margin-top: 24px; color: var(--soz-ink); }
.business-interactions-example__heading { display: grid; gap: 7px; }
.business-interactions-example__eyebrow { color: #a9470b; font-size: 13px; font-weight: 800; }
.business-interactions-example h2 { margin: 0; font-size: clamp(22px, 2.5vw, 29px); line-height: 1.25; font-weight: 800; }
.business-interactions-example p { margin: 0; line-height: 1.6; }
.business-interactions-example__heading p { color: var(--soz-muted); font-size: 14px; }
.business-interactions-example__grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
.interaction-card { display: flex; flex-direction: column; gap: 14px; min-width: 0; padding: 20px; border: 1px solid rgba(17, 34, 45, .1); border-radius: 18px; background: #fff; box-shadow: 0 12px 26px rgba(17, 34, 45, .04); overflow-wrap: anywhere; }
.interaction-card h3 { display: flex; align-items: center; gap: 9px; margin: 0; font-size: 19px; line-height: 1.3; font-weight: 800; }
.interaction-card h3 .q-icon { color: #cb550e; font-size: 24px; }
.interaction-card > p, .interaction-card__footer p { color: var(--soz-muted); font-size: 14px; }
.interaction-sample { display: grid; gap: 7px; min-width: 0; padding: 13px; border: 1px solid rgba(17, 34, 45, .08); border-radius: 12px; background: #faf9fc; font-size: 14px; }
.interaction-sample small { color: var(--soz-muted); line-height: 1.5; }
.interaction-sample--notification { border-inline-start: 3px solid #f17a31; background: #fff7ef; }
.interaction-sample--reply { margin-inline-start: 10px; background: #fff; }
.interaction-card__status, .interaction-card__author { display: flex; align-items: center; flex-wrap: wrap; gap: 7px; }
.interaction-card__status { color: #a9470b; }
.interaction-card__author > strong { flex: 1; min-width: 0; }
.interaction-card__footer { display: grid; gap: 5px; margin-top: auto; font-size: 14px; }
.interaction-card__selector { display: flex; gap: 8px; }
.interaction-card__selector button { flex: 1; min-height: 38px; padding: 7px 10px; border: 1px solid #e7d9d0; border-radius: 9px; background: #fff; color: #713b1c; font: inherit; font-size: 14px; font-weight: 750; cursor: pointer; }
.interaction-card__selector button[aria-pressed="true"] { border-color: #e36b23; background: #fff0e2; color: #9a3c05; }
.interaction-card__thread { display: grid; gap: 11px; }
.interaction-card__item { font-size: 15px; }
.interaction-card__like { display: inline-flex; align-items: center; justify-self: start; gap: 6px; min-height: 36px; padding: 4px 8px; border: 0; border-radius: 8px; background: transparent; color: #8b4318; font: inherit; font-size: 13px; cursor: pointer; }
.interaction-card__like .q-icon { font-size: 19px; }
.interaction-card__like--active { background: #fff0e2; color: #a9470b; }
.interaction-card__recommendation { display: flex; align-items: center; gap: 9px; padding-top: 9px; border-top: 1px solid rgba(17, 34, 45, .09); color: #9a3c05; text-decoration: none; }
.interaction-card__recommendation > .q-icon { font-size: 23px; }
.interaction-card__recommendation > span { display: grid; gap: 3px; }
.interaction-card__nearby { align-self: start; margin-top: auto; color: #a9470b; font-size: 14px; font-weight: 800; text-underline-offset: 3px; }
.interaction-card button:focus-visible, .interaction-card a:focus-visible { outline: 3px solid #f6a15f; outline-offset: 3px; }
@media (max-width: 1050px) {
  .business-interactions-example__grid { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
  .interaction-card { gap: 12px; padding: 16px; }
  .interaction-sample--reply { margin-inline-start: 5px; }
}
</style>
