<script setup>
	import { computed, ref, watch } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { absoluteUrl, useSeo } from '@/composables/useSeo'
	import { CATALOG_SCOPES } from '@/constants/catalogTopics'
	import { findPresencePalette, presencePalettes } from '@/constants/presencePalettes'
	import AdCard from '@/components/AdCard.vue'
	import CatalogCategorySelect from '@/components/CatalogCategorySelect.vue'
	import EventCard from '@/components/events/EventCard.vue'
	import ProductCard from '@/components/products/ProductCard.vue'
	import ServiceCard from '@/components/services/ServiceCard.vue'
	import PagePreview from '@/components/pages/PagePreview.vue'

	const { locale, t } = useI18n()
	const route = useRoute()
	const { catalogGroups, scopedCatalogGroups, loadCatalogTopics } = useCatalogTopics()
	const activeTab = ref('preview')
	const exampleType = computed(() => (route.meta.exampleType === 'community' ? 'community' : 'business'))
	const isCommunityExample = computed(() => exampleType.value === 'community')
	const bannerUrl = computed(() => `/assets/landing/example-${exampleType.value}-banner-1440.v1.webp`)
	const logoUrl = computed(() => `/assets/landing/example-${exampleType.value}-logo-512.v1.webp`)
	const palette = computed(() => findPresencePalette('orange-violet'))
	const examplePath = computed(() => (isCommunityExample.value ? '/community-example-page' : '/business-example-page'))
	const pageCatalogScope = computed(() => (
		isCommunityExample.value ? CATALOG_SCOPES.COMMUNITY_PAGES : CATALOG_SCOPES.BUSINESS_PAGES
	))
	const demoCatalogGroups = computed(() => scopedCatalogGroups.get(pageCatalogScope.value) || catalogGroups.value)
	const demoCategoryKey = computed(() => (
		isCommunityExample.value ? 'community_pages.local.neighborhood_group' : 'shopping_retail.sales_special_offers'
	))
	const requiredLabel = (key) => `${t(key)} *`
	const dayLabel = (weekday) => t(`pages.weekdays.${weekday}`)

	const typedCopyByLocale = {
		en: {
			business: {
				seoTitle: 'Business example page with store, services and ads',
				seoDescription: 'Preview a filled Sveevee business example page with products, services, ads, ratings, address, opening hours, social links, chat, WhatsApp and maps.',
				title: 'Business example page',
				subtitle: 'A static Sveevee business page example with preview, settings, store, services and ads tabs enabled.',
				badge: 'Free business page example',
				pageName: 'Ramot Local Studio',
				pageDescription: 'A filled local business page example with contact details, address, opening hours, socials, ratings, products, services and ads in one public Sveevee page.',
				allEnabled: 'Store, services and ads are enabled'
			},
			community: {
				seoTitle: 'Community example page with events and ads',
				seoDescription: 'Preview a filled Sveevee community example page with events, ads, ratings, address, opening hours, social links, chat, WhatsApp and maps.',
				title: 'Community example page',
				subtitle: 'A static Sveevee community page example with preview, settings, events and ads tabs enabled.',
				badge: 'Free community page example',
				pageName: 'Ramot Community Circle',
				pageDescription: 'A filled local community page example with contact details, address, opening hours, socials, ratings, events and community ads in one public Sveevee page.',
				allEnabled: 'Events and ads are enabled'
			}
		},
		he: {
			business: {
				seoTitle: 'עמוד דוגמה לעסק עם חנות, שירותים ומודעות',
				seoDescription: 'צפו בעמוד דוגמה לעסק ב-Sveevee עם מוצרים, שירותים, מודעות, דירוגים, כתובת, שעות פתיחה, רשתות חברתיות, צאט, WhatsApp ומפות.',
				title: 'עמוד דוגמה לעסק',
				subtitle: 'דוגמה סטטית לעמוד עסק ב-Sveevee עם טאבים של תצוגה מקדימה, הגדרות, חנות, שירותים ומודעות.',
				badge: 'דוגמה לעמוד עסק חינמי',
				pageName: 'Ramot Local Studio',
				pageDescription: 'דוגמה לעמוד עסק מקומי מלא עם פרטי קשר, כתובת, שעות פתיחה, רשתות חברתיות, דירוגים, מוצרים, שירותים ומודעות בעמוד ציבורי אחד.',
				allEnabled: 'חנות, שירותים ומודעות פעילים'
			},
			community: {
				seoTitle: 'עמוד דוגמה לקהילה עם אירועים ומודעות',
				seoDescription: 'צפו בעמוד דוגמה לקהילה ב-Sveevee עם אירועים, מודעות, דירוגים, כתובת, שעות פעילות, רשתות חברתיות, צאט, WhatsApp ומפות.',
				title: 'עמוד דוגמה לקהילה',
				subtitle: 'דוגמה סטטית לעמוד קהילה ב-Sveevee עם טאבים של תצוגה מקדימה, הגדרות, אירועים ומודעות.',
				badge: 'דוגמה לעמוד קהילה חינמי',
				pageName: 'Ramot Community Circle',
				pageDescription: 'דוגמה לעמוד קהילה מקומי מלא עם פרטי קשר, כתובת, שעות פעילות, רשתות חברתיות, דירוגים, אירועים ומודעות קהילה בעמוד ציבורי אחד.',
				allEnabled: 'אירועים ומודעות פעילים'
			}
		},
		ru: {
			business: {
				seoTitle: 'Пример бизнес-страницы с магазином, услугами и объявлениями',
				seoDescription: 'Посмотрите пример бизнес-страницы Sveevee с товарами, услугами, объявлениями, рейтингами, адресом, часами работы, соцсетями, чатом, WhatsApp и картами.',
				title: 'Пример бизнес-страницы',
				subtitle: 'Статический пример бизнес-страницы Sveevee с предпросмотром, настройками, магазином, услугами и объявлениями.',
				badge: 'Пример бесплатной бизнес-страницы',
				pageName: 'Ramot Local Studio',
				pageDescription: 'Заполненный пример локальной бизнес-страницы с контактами, адресом, часами работы, соцсетями, рейтингами, товарами, услугами и объявлениями.',
				allEnabled: 'Магазин, услуги и объявления включены'
			},
			community: {
				seoTitle: 'Пример страницы сообщества с событиями и объявлениями',
				seoDescription: 'Посмотрите пример страницы сообщества Sveevee с событиями, объявлениями, рейтингами, адресом, часами работы, соцсетями, чатом, WhatsApp и картами.',
				title: 'Пример страницы сообщества',
				subtitle: 'Статический пример страницы сообщества Sveevee с предпросмотром, настройками, событиями и объявлениями.',
				badge: 'Пример бесплатной страницы сообщества',
				pageName: 'Ramot Community Circle',
				pageDescription: 'Заполненный пример локальной страницы сообщества с контактами, адресом, часами работы, соцсетями, рейтингами, событиями и объявлениями.',
				allEnabled: 'События и объявления включены'
			}
		},
		fr: {
			business: {
				seoTitle: 'Page exemple entreprise avec boutique, services et annonces',
				seoDescription: 'Visualisez une page exemple entreprise Sveevee avec produits, services, annonces, avis, adresse, horaires, liens sociaux, chat, WhatsApp et cartes.',
				title: 'Page exemple entreprise',
				subtitle: 'Exemple statique de page entreprise Sveevee avec apercu, parametres, boutique, services et annonces.',
				badge: 'Exemple de page entreprise gratuite',
				pageName: 'Ramot Local Studio',
				pageDescription: 'Exemple de page entreprise locale avec contact, adresse, horaires, reseaux sociaux, avis, produits, services et annonces dans une page publique.',
				allEnabled: 'Boutique, services et annonces sont actifs'
			},
			community: {
				seoTitle: 'Page exemple communaute avec evenements et annonces',
				seoDescription: 'Visualisez une page exemple communaute Sveevee avec evenements, annonces, avis, adresse, horaires, liens sociaux, chat, WhatsApp et cartes.',
				title: 'Page exemple communaute',
				subtitle: 'Exemple statique de page communaute Sveevee avec apercu, parametres, evenements et annonces.',
				badge: 'Exemple de page communaute gratuite',
				pageName: 'Ramot Community Circle',
				pageDescription: 'Exemple de page communaute locale avec contact, adresse, horaires, reseaux sociaux, avis, evenements et annonces dans une page publique.',
				allEnabled: 'Evenements et annonces sont actifs'
			}
		}
	}

	const copyByLocale = {
		en: {
			seoTitle: 'Example page with all Sveevee modules',
			seoDescription: 'Preview a filled Sveevee example page with products, services, events, ads, ratings, address, opening hours, social links, chat, WhatsApp, and maps.',
			title: 'Example page',
			subtitle: 'A static Sveevee page example with preview, settings, store, services, events, and ads tabs enabled.',
			register: 'Register to start',
			badge: 'Free page example',
			pageName: 'Ramot Local Studio',
			pageDescription: 'A filled local page example showing how a business or community can present contact details, address, opening hours, socials, ratings, products, services, events, and ads in one public Sveevee page.',
			location: 'Jerusalem, Ramot',
			settingsTitle: 'Filled settings',
			modulesTitle: 'Modules',
			allEnabled: 'All modules are enabled',
			products: 'Products',
			services: 'Services',
			events: 'Events',
			ads: 'Ads',
			details: {
				contact: 'Phone, email, WhatsApp, chat and share are filled.',
				address: 'Ha-Pisga 18, Ramot, Jerusalem',
				socials: 'Facebook, Instagram, TikTok and Telegram links are filled.',
				hours: 'Sunday-Thursday 09:00-18:00, Friday 09:00-13:00.'
			},
			items: {
				products: [
					{ name: 'Handmade gift basket', price: '₪120', body: 'Local gift basket with plants, soaps, and a greeting option.' },
					{ name: 'Home office shelf', price: '₪260', body: 'Compact shelf unit with delivery inside Jerusalem neighborhoods.' },
					{ name: 'Neighborhood event kit', price: '₪90', body: 'Reusable cups, badges, signs, and table decor for local meetups.' }
				],
				services: [
					{ name: 'Small business page setup', body: 'Profile text, category, photos, hours, and first products prepared for publishing.' },
					{ name: 'Community event planning', body: 'Event details, location, schedule, and announcements prepared for residents.' },
					{ name: 'Local delivery coordination', body: 'Pickup windows, delivery notes, and direct customer messages organized clearly.' }
				],
				events: [
					{ name: 'Friday makers meetup', body: 'A small local meetup for handmade products, neighbors, and community sellers.' },
					{ name: 'Family repair workshop', body: 'Bring a small item, learn basic repair habits, and meet people nearby.' }
				],
				ads: [
					{ title: 'Opening offer for new customers', body: 'Free local delivery this week for the first store order through the page.', type: 'business_ad' },
					{ title: 'Volunteers wanted for a community evening', body: 'Two people needed to welcome guests and arrange the event table.', type: 'community_ad' },
					{ title: 'Used display table for sale', body: 'Light wooden table, good condition, pickup from Ramot.', type: 'private_ad' }
				]
			}
		},
		he: {
			seoTitle: 'עמוד דוגמה עם כל המודולים של Sveevee',
			seoDescription: 'צפו בעמוד דוגמה מלא של Sveevee עם מוצרים, שירותים, אירועים, מודעות, דירוגים, כתובת, שעות פתיחה, רשתות חברתיות, צאט, WhatsApp ומפות.',
			title: 'עמוד דוגמה',
			subtitle: 'דוגמה סטטית לעמוד Sveevee עם טאבים של תצוגה מקדימה, הגדרות, חנות, שירותים, אירועים ומודעות.',
			register: 'הרשמה להתחלה',
			badge: 'דוגמה לעמוד חינמי',
			pageName: 'Ramot Local Studio',
			pageDescription: 'דוגמה לעמוד מקומי מלא שמראה איך עסק או קהילה יכולים להציג פרטי קשר, כתובת, שעות פתיחה, רשתות חברתיות, דירוגים, מוצרים, שירותים, אירועים ומודעות בעמוד ציבורי אחד.',
			location: 'ירושלים, רמות',
			settingsTitle: 'הגדרות מלאות',
			modulesTitle: 'מודולים',
			allEnabled: 'כל המודולים פעילים',
			products: 'מוצרים',
			services: 'שירותים',
			events: 'אירועים',
			ads: 'מודעות',
			details: {
				contact: 'טלפון, אימייל, WhatsApp, צאט ושיתוף מלאים.',
				address: 'הפסגה 18, רמות, ירושלים',
				socials: 'קישורי Facebook, Instagram, TikTok ו-Telegram מלאים.',
				hours: 'ראשון-חמישי 09:00-18:00, שישי 09:00-13:00.'
			},
			items: {
				products: [
					{ name: 'סל מתנה בעבודת יד', price: '₪120', body: 'סל מקומי עם עציצים, סבונים ואפשרות לברכה אישית.' },
					{ name: 'מדף למשרד ביתי', price: '₪260', body: 'יחידת מדפים קומפקטית עם משלוח בשכונות ירושלים.' },
					{ name: 'ערכת אירוע שכונתי', price: '₪90', body: 'כוסות רב פעמיות, תגי שם, שלטים ועיצוב שולחן למפגשים מקומיים.' }
				],
				services: [
					{ name: 'הקמת עמוד לעסק קטן', body: 'טקסט פרופיל, קטגוריה, תמונות, שעות ומוצרים ראשונים מוכנים לפרסום.' },
					{ name: 'תכנון אירוע קהילתי', body: 'פרטי אירוע, מיקום, לוח זמנים והודעות מוכנים לתושבים.' },
					{ name: 'תיאום משלוחים מקומי', body: 'חלונות איסוף, הערות משלוח והודעות לקוחות מסודרים בבירור.' }
				],
				events: [
					{ name: 'מפגש יוצרים ביום שישי', body: 'מפגש מקומי קטן למוצרים בעבודת יד, שכנים ומוכרים קהילתיים.' },
					{ name: 'סדנת תיקונים למשפחות', body: 'מביאים פריט קטן, לומדים תיקון בסיסי ומכירים אנשים מהאזור.' }
				],
				ads: [
					{ title: 'מבצע פתיחה ללקוחות חדשים', body: 'משלוח מקומי חינם השבוע להזמנה הראשונה דרך העמוד.', type: 'business_ad' },
					{ title: 'דרושים מתנדבים לערב קהילתי', body: 'דרושים שני אנשים לקבלת אורחים וסידור שולחן האירוע.', type: 'community_ad' },
					{ title: 'שולחן תצוגה יד שנייה למכירה', body: 'שולחן עץ קל במצב טוב, איסוף מרמות.', type: 'private_ad' }
				]
			}
		},
		ru: {
			seoTitle: 'Пример страницы со всеми модулями Sveevee',
			seoDescription: 'Посмотрите заполненную примерную страницу Sveevee с товарами, услугами, событиями, объявлениями, рейтингами, адресом, часами работы, соцсетями, чатом, WhatsApp и картами.',
			title: 'Пример страницы',
			subtitle: 'Статический пример страницы Sveevee с вкладками предпросмотра, настроек, магазина, услуг, событий и объявлений.',
			register: 'Зарегистрироваться',
			badge: 'Пример бесплатной страницы',
			pageName: 'Ramot Local Studio',
			pageDescription: 'Заполненный пример локальной страницы: контакты, адрес, часы работы, соцсети, рейтинги, товары, услуги, события и объявления в одной публичной странице.',
			location: 'Иерусалим, Рамот',
			settingsTitle: 'Заполненные настройки',
			modulesTitle: 'Модули',
			allEnabled: 'Все модули включены',
			products: 'Товары',
			services: 'Услуги',
			events: 'События',
			ads: 'Объявления',
			details: {
				contact: 'Телефон, email, WhatsApp, чат и sharing заполнены.',
				address: 'Ha-Pisga 18, Ramot, Jerusalem',
				socials: 'Facebook, Instagram, TikTok и Telegram заполнены.',
				hours: 'Воскресенье-четверг 09:00-18:00, пятница 09:00-13:00.'
			},
			items: {
				products: [
					{ name: 'Подарочная корзина ручной работы', price: '₪120', body: 'Локальная корзина с растениями, мылом и открыткой.' },
					{ name: 'Полка для домашнего офиса', price: '₪260', body: 'Компактная полка с доставкой по районам Иерусалима.' },
					{ name: 'Набор для районного события', price: '₪90', body: 'Стаканы, бейджи, таблички и декор для локальной встречи.' }
				],
				services: [
					{ name: 'Настройка страницы бизнеса', body: 'Текст профиля, категория, фото, часы и первые товары для публикации.' },
					{ name: 'Планирование события', body: 'Детали события, место, расписание и объявления для жителей.' },
					{ name: 'Координация доставки', body: 'Окна самовывоза, доставка и сообщения клиентам организованы ясно.' }
				],
				events: [
					{ name: 'Пятничная встреча мастеров', body: 'Локальная встреча для handmade-товаров, соседей и продавцов.' },
					{ name: 'Семейная мастерская ремонта', body: 'Принесите небольшой предмет, учитесь ремонту и знакомьтесь рядом.' }
				],
				ads: [
					{ title: 'Стартовое предложение', body: 'Бесплатная локальная доставка на первый заказ через страницу.', type: 'business_ad' },
					{ title: 'Нужны волонтеры', body: 'Нужны два человека для встречи гостей и подготовки стола.', type: 'community_ad' },
					{ title: 'Витринный стол б/у', body: 'Легкий деревянный стол в хорошем состоянии, самовывоз из Рамота.', type: 'private_ad' }
				]
			}
		},
		fr: {
			seoTitle: 'Page exemple avec tous les modules Sveevee',
			seoDescription: 'Visualisez une page exemple Sveevee complete avec produits, services, evenements, annonces, avis, adresse, horaires, liens sociaux, chat, WhatsApp et cartes.',
			title: 'Page exemple',
			subtitle: 'Exemple statique de page Sveevee avec onglets apercu, parametres, boutique, services, evenements et annonces.',
			register: 'S inscrire pour commencer',
			badge: 'Exemple de page gratuite',
			pageName: 'Ramot Local Studio',
			pageDescription: 'Exemple de page locale complete avec contact, adresse, horaires, reseaux sociaux, avis, produits, services, evenements et annonces dans une page publique.',
			location: 'Jerusalem, Ramot',
			settingsTitle: 'Parametres remplis',
			modulesTitle: 'Modules',
			allEnabled: 'Tous les modules sont actifs',
			products: 'Produits',
			services: 'Services',
			events: 'Evenements',
			ads: 'Annonces',
			details: {
				contact: 'Telephone, e-mail, WhatsApp, chat et partage sont remplis.',
				address: 'Ha-Pisga 18, Ramot, Jerusalem',
				socials: 'Facebook, Instagram, TikTok et Telegram sont remplis.',
				hours: 'Dimanche-jeudi 09:00-18:00, vendredi 09:00-13:00.'
			},
			items: {
				products: [
					{ name: 'Panier cadeau fait main', price: '₪120', body: 'Panier local avec plantes, savons et message personnel.' },
					{ name: 'Etagere bureau maison', price: '₪260', body: 'Etagere compacte avec livraison dans les quartiers de Jerusalem.' },
					{ name: 'Kit evenement de quartier', price: '₪90', body: 'Gobelets, badges, panneaux et decoration pour rencontres locales.' }
				],
				services: [
					{ name: 'Creation de page entreprise', body: 'Texte, categorie, photos, horaires et premiers produits prets a publier.' },
					{ name: 'Preparation evenement communaute', body: 'Details, lieu, horaire et annonces prepares pour les habitants.' },
					{ name: 'Coordination livraison locale', body: 'Retraits, livraisons et messages clients organises clairement.' }
				],
				events: [
					{ name: 'Rencontre createurs du vendredi', body: 'Petite rencontre locale pour produits faits main, voisins et vendeurs.' },
					{ name: 'Atelier reparation famille', body: 'Apportez un objet, apprenez les bases et rencontrez des voisins.' }
				],
				ads: [
					{ title: 'Offre ouverture nouveaux clients', body: 'Livraison locale gratuite pour la premiere commande via la page.', type: 'business_ad' },
					{ title: 'Benevoles recherches', body: 'Deux personnes pour accueillir les invites et organiser la table.', type: 'community_ad' },
					{ title: 'Table de presentation occasion', body: 'Table en bois leger, bon etat, retrait a Ramot.', type: 'private_ad' }
				]
			}
		}
	}

	const copy = computed(() => copyByLocale[locale.value] || copyByLocale.en)
	const typedCopy = computed(() => typedCopyByLocale[locale.value]?.[exampleType.value] || typedCopyByLocale.en[exampleType.value])
	const exampleModules = computed(() => {
		if (isCommunityExample.value) {
			return [
				{ name: 'events', label: copy.value.events, icon: 'event' }
			]
		}

		return [
			{ name: 'store', label: copy.value.products, icon: 'inventory_2' },
			{ name: 'services', label: copy.value.services, icon: 'design_services' }
		]
	})
	const tabs = computed(() => [
		{ name: 'preview', label: t('pages.tabs.preview'), icon: 'visibility' },
		{ name: 'settings', label: t('pages.tabs.settings'), icon: 'settings' },
		...exampleModules.value,
		{ name: 'ads', label: copy.value.ads, icon: 'campaign' }
	])
	const openingHours = computed(() => [
		{ weekday: 'sunday', is_open: true, opens_at: '09:00', closes_at: '18:00' },
		{ weekday: 'monday', is_open: true, opens_at: '09:00', closes_at: '18:00' },
		{ weekday: 'tuesday', is_open: true, opens_at: '09:00', closes_at: '18:00' },
		{ weekday: 'wednesday', is_open: true, opens_at: '09:00', closes_at: '18:00' },
		{ weekday: 'thursday', is_open: true, opens_at: '09:00', closes_at: '18:00' },
		{ weekday: 'friday', is_open: true, opens_at: '09:00', closes_at: '13:00' },
		{ weekday: 'saturday', is_open: false, opens_at: null, closes_at: null }
	])
	const locationParts = computed(() => copy.value.location.split(',').map((part) => part.trim()).filter(Boolean))
	const demoAddress = computed(() => ({
		street: 'Ha-Pisga',
		number: '18',
		city: locationParts.value[0] || '',
		neighborhood: locationParts.value[1] || ''
	}))
	const demoForm = computed(() => ({
		name: typedCopy.value.pageName,
		public_description: typedCopy.value.pageDescription,
		category_key: demoCategoryKey.value,
		phone: '+972 50 123 4567',
		contact_email: 'hello@ramotstudio.example',
		whatsapp: '+972 50 123 4567',
		socials: {
			facebook: 'sveevee',
			instagram: 'sveevee',
			tiktok: 'sveevee',
			telegram: 'sveevee'
		},
		address: demoAddress.value,
		opening_hours: openingHours.value,
		palette_key: palette.value.key
	}))
	const demoCityOptions = computed(() => demoAddress.value.city ? [demoAddress.value.city] : [])
	const demoNeighborhoodOptions = computed(() => demoAddress.value.neighborhood ? [demoAddress.value.neighborhood] : [])
	const logoDisplayName = computed(() => `example-${exampleType.value}-logo-512.v1.webp`)
	const bannerDisplayName = computed(() => `example-${exampleType.value}-banner-1440.v1.webp`)
	const previewPage = computed(() => ({
		id: 1,
		type: exampleType.value,
		name: typedCopy.value.pageName,
		public_description: typedCopy.value.pageDescription,
		contact_email: 'hello@ramotstudio.example',
		phone: '+972 50 123 4567',
		contact: {
			tel: '+972 50 123 4567',
			email: 'hello@ramotstudio.example',
			whatsapp: '+972 50 123 4567'
		},
		socials: {
			facebook: 'sveevee',
			instagram: 'sveevee',
			tiktok: 'sveevee',
			telegram: 'sveevee'
		},
		address_details: {
			street: 'Ha-Pisga',
			number: '18',
			city: demoAddress.value.city,
			neighborhood: demoAddress.value.neighborhood
		},
		opening_hours: openingHours.value,
		features: {
			store: !isCommunityExample.value,
			services: !isCommunityExample.value,
			events: isCommunityExample.value
		},
		logo_url: logoUrl.value,
		banner_url: bannerUrl.value,
		rating_summary: { average: 4.8, count: 24 }
	}))
	const shareUrl = computed(() => absoluteUrl(examplePath.value))
	const placeholderCardCounts = {
		store: 2,
		services: 1,
		events: 1,
		ads: 1
	}
	const placeholders = computed(() => exampleModules.value.map((module) => ({
		key: module.name,
		icon: module.icon,
		label: module.label,
		tabName: module.name,
		cardCount: placeholderCardCounts[module.name] || 1
	})))
	const products = computed(() => copy.value.items.products.map((item, index) => ({
		id: index + 1,
		name: item.name,
		description: item.body,
		price: Number(item.price.replace(/\D/g, '')) || null,
		price_label: item.price,
		link: 'https://sveevee.co.il',
		image_url: [
			'/assets/examples/example-product-gift-basket.v1.webp',
			'/assets/examples/example-product-office-shelf.v1.webp',
			'/assets/examples/example-product-event-kit.v1.webp'
		][index]
	})))
	const services = computed(() => copy.value.items.services.map((item, index) => ({
		id: index + 1,
		name: item.name,
		description: item.body,
		link: 'https://sveevee.co.il',
		image_url: [
			'/assets/examples/example-service-page-setup.v1.webp',
			'/assets/examples/example-service-event-planning.v1.webp',
			'/assets/examples/example-service-delivery.v1.webp'
		][index]
	})))
	const events = computed(() => copy.value.items.events.map((item, index) => ({
		id: index + 1,
		name: item.name,
		description: item.body,
		date: ['2026-09-04', '2026-09-08'][index],
		time: ['10:00', '17:30'][index],
		end_time: ['12:30', '19:00'][index],
		address: copy.value.details.address,
		image_url: [
			'/assets/examples/example-event-makers-meetup.v1.webp',
			'/assets/examples/example-event-repair-workshop.v1.webp'
		][index]
	})))
	const ads = computed(() => copy.value.items.ads
		.filter((item) => {
			const allowedTypes = isCommunityExample.value ? ['community_ad', 'private_ad'] : ['business_ad', 'private_ad']
			return allowedTypes.includes(item.type)
		})
		.map((item, index) => {
			const adImages = {
				business_ad: '/assets/examples/example-ad-opening-offer.v1.webp',
				community_ad: '/assets/examples/example-ad-volunteers.v1.webp',
				private_ad: '/assets/examples/example-ad-display-table.v1.webp'
			}

			return {
				id: index + 1,
				title: item.title,
				text: item.body,
				type: item.type,
				city: copy.value.location.split(',')[0],
				neighborhood: copy.value.location.split(',')[1]?.trim() || '',
				category: null,
				image_url: adImages[item.type] || adImages.private_ad
			}
		}))

	function openTab(tabName) {
		activeTab.value = tabName
	}

	watch(pageCatalogScope, (scope) => {
		loadCatalogTopics(scope).catch(() => {})
	}, { immediate: true })

	watch(exampleType, () => {
		activeTab.value = 'preview'
	})

	useSeo(computed(() => ({
		title: typedCopy.value.seoTitle,
		description: typedCopy.value.seoDescription,
		image: bannerUrl.value,
		canonical: examplePath.value,
		jsonLd: {
			'@context': 'https://schema.org',
			'@type': 'WebPage',
			name: typedCopy.value.seoTitle,
			description: typedCopy.value.seoDescription,
			url: absoluteUrl(examplePath.value),
			image: absoluteUrl(bannerUrl.value)
		}
	})))
</script>

<template>
	<q-page padding class="setup-page example-setup-page">
		<div class="page-shell">
			<section class="soz-section-card page-head">
				<div class="page-head__copy">
					<q-chip dense color="white" text-color="primary" class="page-head__chip">
						{{ typedCopy.badge }}
					</q-chip>
					<h1 class="soz-page-title">{{ typedCopy.title }}</h1>
					<p>{{ typedCopy.subtitle }}</p>
					<q-btn
						color="primary"
						unelevated
						rounded
						icon="person_add"
						class="page-head__cta"
						:label="copy.register"
						:to="{ name: 'register' }"
					/>
				</div>
			</section>

			<q-tabs
				v-model="activeTab"
				class="setup-tabs q-mt-lg"
				active-color="primary"
				indicator-color="primary"
				align="left"
				no-caps
				inline-label
				mobile-arrows
				outside-arrows
			>
				<q-tab
					v-for="tab in tabs"
					:key="tab.name"
					:name="tab.name"
					:icon="tab.icon"
					:label="tab.label"
				/>
			</q-tabs>

			<q-tab-panels v-model="activeTab" animated class="setup-panels">
				<q-tab-panel name="preview" class="setup-panel">
					<section class="soz-section-card panel">
						<PagePreview
							:page="previewPage"
							:palette="palette"
							:has-after-info="true"
							:share-url="shareUrl"
							title-tag="h1"
						>
							<template #afterInfo>
								<div class="preview-placeholder-list">
									<div
										v-for="placeholder in placeholders"
										:key="placeholder.key"
										class="preview-placeholder-segment"
									>
										<button
											type="button"
											class="preview-placeholder-heading"
											@click="openTab(placeholder.tabName)"
										>
											<span class="preview-placeholder-heading__icon">
												<q-icon :name="placeholder.icon" size="22px" />
											</span>
											<span>{{ placeholder.label }}</span>
											<q-icon name="south" class="preview-placeholder-heading__arrow" />
										</button>
										<div class="preview-placeholder-card-grid" :class="{ 'preview-placeholder-card-grid--two': placeholder.cardCount === 2 }">
											<div
												v-for="index in placeholder.cardCount"
												:key="`${placeholder.key}-${index}`"
												class="preview-placeholder-card"
											>
												<span class="preview-placeholder-card__media" />
												<span class="preview-placeholder-card__line preview-placeholder-card__line--strong" />
												<span class="preview-placeholder-card__line" />
												<span class="preview-placeholder-card__line preview-placeholder-card__line--short" />
											</div>
										</div>
									</div>
								</div>
							</template>
						</PagePreview>
					</section>
				</q-tab-panel>

				<q-tab-panel name="settings" class="setup-panel">
					<div class="settings-grid">
						<section class="soz-section-card panel">
							<div class="panel-head panel-head--compact">
								<h2>{{ copy.modulesTitle }}</h2>
							</div>
							<div class="feature-toggle-row">
								<button
									v-for="tab in exampleModules"
									:key="tab.name"
									type="button"
									class="feature-toggle feature-toggle--active"
									:aria-label="tab.label"
									aria-pressed="true"
									disabled
								>
									<span class="feature-toggle__dot" aria-hidden="true" />
									<span>{{ tab.label }}</span>
								</button>
							</div>
							<p class="example-settings-note">{{ typedCopy.allEnabled }}</p>
						</section>

						<section class="soz-section-card panel">
							<div class="panel-head">
								<h2>{{ t('pages.tabs.settings') }}</h2>
								<q-btn rounded
									unelevated
									color="negative"
									class="page-delete-btn"
									icon="delete"
									disable
									:label="t('actions.deletePage')"
								/>
							</div>
							<div class="presence-grid">
								<div class="presence-editor example-disabled-form" aria-disabled="true">
									<q-form greedy class="column q-gutter-md">
										<q-input :model-value="demoForm.name" outlined disable :label="requiredLabel('pages.name')" />
										<q-input
											:model-value="demoForm.public_description"
											outlined
											disable
											type="textarea"
											autogrow
											:input-style="{ minHeight: '150px' }"
											:label="t('pages.description')"
										/>
										<CatalogCategorySelect
											:model-value="demoForm.category_key"
											:groups="demoCatalogGroups"
											:scope="pageCatalogScope"
											required
											disabled
											:label="requiredLabel('catalog.category')"
										/>

										<section class="presence-segment">
											<div class="presence-segment__title">{{ t('pages.sections.contact') }}</div>
											<div class="row q-col-gutter-md">
												<div class="col-12 col-md-4">
													<q-input :model-value="demoForm.phone" outlined disable :label="requiredLabel('pages.tel')" />
												</div>
												<div class="col-12 col-md-4">
													<q-input :model-value="demoForm.contact_email" outlined disable type="email" :label="requiredLabel('pages.email')" />
												</div>
												<div class="col-12 col-md-4">
													<q-input :model-value="demoForm.whatsapp" outlined disable :label="t('pages.whatsapp')" />
												</div>
											</div>
										</section>

										<section class="presence-segment">
											<div class="presence-segment__title">{{ t('pages.sections.address') }}</div>
											<div class="row q-col-gutter-md">
												<div class="col-12 col-md-4">
													<q-input :model-value="demoForm.address.street" outlined disable :label="requiredLabel('pages.street')" />
												</div>
												<div class="col-12 col-md-2">
													<q-input :model-value="demoForm.address.number" outlined disable :label="requiredLabel('pages.number')" />
												</div>
												<div class="col-12 col-md-3">
													<q-select
														:model-value="demoForm.address.city"
														outlined
														disable
														emit-value
														map-options
														:options="demoCityOptions"
														:label="requiredLabel('pages.city')"
													/>
												</div>
												<div class="col-12 col-md-3">
													<q-select
														:model-value="demoForm.address.neighborhood"
														outlined
														disable
														emit-value
														map-options
														:options="demoNeighborhoodOptions"
														:label="t('auth.neighborhood')"
													/>
												</div>
											</div>
										</section>

										<section class="presence-segment">
											<div class="presence-segment__title">{{ t('pages.sections.socials') }}</div>
											<div class="row q-col-gutter-md">
												<div class="col-12 col-md-3">
													<q-input :model-value="demoForm.socials.facebook" outlined disable label="Facebook" />
												</div>
												<div class="col-12 col-md-3">
													<q-input :model-value="demoForm.socials.instagram" outlined disable label="Instagram" />
												</div>
												<div class="col-12 col-md-3">
													<q-input :model-value="demoForm.socials.tiktok" outlined disable label="TikTok" />
												</div>
												<div class="col-12 col-md-3">
													<q-input :model-value="demoForm.socials.telegram" outlined disable label="Telegram" />
												</div>
											</div>
										</section>

										<section class="presence-segment">
											<div class="presence-segment__title">{{ t('pages.sections.openingHours') }}</div>
											<div class="hours-grid">
												<div v-for="item in demoForm.opening_hours" :key="item.weekday" class="hours-row">
													<div class="hours-row__day text-body2 text-weight-medium">{{ dayLabel(item.weekday) }}</div>
													<q-toggle :model-value="item.is_open" disable :label="item.is_open ? t('pages.open') : t('pages.closed')" color="primary" />
													<q-input :model-value="item.opens_at" outlined disable type="time" :label="t('pages.opensAt')" />
													<q-input :model-value="item.closes_at" outlined disable type="time" :label="t('pages.closesAt')" />
												</div>
											</div>
										</section>

										<div class="upload-group q-mt-md">
											<div class="upload-row">
												<q-file
													:model-value="null"
													outlined
													disable
													accept="image/*"
													:display-value="logoDisplayName"
													:label="t('pages.logo')"
												/>
												<q-btn type="button"
													rounded
													outline
													color="dark"
													disable
													:label="t('pages.uploadLogo')"
												/>
											</div>

											<div class="upload-row">
												<q-file
													:model-value="null"
													outlined
													disable
													accept="image/*"
													:display-value="bannerDisplayName"
													:label="t('pages.banner')"
												/>
												<q-btn type="button"
													rounded
													outline
													color="dark"
													disable
													:label="t('pages.uploadBanner')"
												/>
											</div>
										</div>

										<div class="section-label">{{ t('pages.palette') }}</div>
										<div class="palette-grid">
											<button
												v-for="item in presencePalettes"
												:key="item.key"
												type="button"
												class="palette-card"
												:class="{ 'palette-card--active': item.key === demoForm.palette_key }"
												:aria-pressed="item.key === demoForm.palette_key"
												disabled
											>
												<q-icon v-if="item.key === demoForm.palette_key" name="check_circle" class="palette-card__check" />
												<span class="palette-card__swatch" :style="{ background: item.hero }" />
												<span class="palette-card__name">{{ t(item.nameKey) }}</span>
											</button>
										</div>

										<div class="row items-center q-col-gutter-sm q-mt-md">
											<div class="col-auto">
												<q-btn rounded
													unelevated
													color="primary"
													type="button"
													disable
													:label="t('pages.saveSettings')"
												/>
											</div>
										</div>
									</q-form>
								</div>
							</div>
						</section>
					</div>
				</q-tab-panel>

				<q-tab-panel v-if="!isCommunityExample" name="store" class="setup-panel">
					<section class="soz-section-card panel">
						<div class="panel-head">
							<h2>{{ t('products.storeTitle') }}</h2>
							<q-btn rounded
								unelevated
								color="primary"
								icon="add_shopping_cart"
								disable
								:label="t('actions.addProduct')"
							/>
						</div>
						<div class="product-grid example-disabled-zone" aria-disabled="true">
							<ProductCard
								v-for="product in products"
								:key="product.id"
								:product="product"
								:palette="palette"
								editable
							/>
						</div>
					</section>
				</q-tab-panel>

				<q-tab-panel v-if="!isCommunityExample" name="services" class="setup-panel">
					<section class="soz-section-card panel">
						<div class="panel-head">
							<h2>{{ t('businessServices.title') }}</h2>
							<q-btn rounded
								unelevated
								color="primary"
								icon="design_services"
								disable
								:label="t('businessServices.addService')"
							/>
						</div>
						<div class="service-list example-disabled-zone" aria-disabled="true">
							<ServiceCard
								v-for="service in services"
								:key="service.id"
								:service="service"
								:palette="palette"
								editable
							/>
						</div>
					</section>
				</q-tab-panel>

				<q-tab-panel v-if="isCommunityExample" name="events" class="setup-panel">
					<section class="soz-section-card panel">
						<div class="panel-head">
							<h2>{{ t('events.eventsTitle') }}</h2>
							<q-btn rounded
								unelevated
								color="primary"
								icon="event"
								disable
								:label="t('actions.addEvent')"
							/>
						</div>
						<div class="event-grid example-disabled-zone" aria-disabled="true">
							<EventCard
								v-for="event in events"
								:key="event.id"
								:event="event"
								:palette="palette"
								editable
							/>
						</div>
					</section>
				</q-tab-panel>

				<q-tab-panel name="ads" class="setup-panel">
					<section class="soz-section-card panel">
						<div class="panel-head">
							<h2>{{ t('ads.listTitle') }}</h2>
							<q-btn rounded
								unelevated
								color="primary"
								icon="add"
								disable
								:label="t('actions.createAd')"
							/>
						</div>
						<div class="listing-grid example-disabled-zone" aria-disabled="true">
							<AdCard
								v-for="ad in ads"
								:key="ad.id"
								:ad="ad"
								:detail-links="false"
								editable
							/>
						</div>
					</section>
				</q-tab-panel>
			</q-tab-panels>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.setup-page {
  padding: 0 20px 36px;
}

.page-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.page-head,
.panel {
  padding: 28px;
}

.page-head {
  display: grid;
  justify-items: center;
  gap: 18px;
  text-align: center;
}

.page-head__copy {
  justify-items: center;
  display: grid;
  gap: 10px;
  max-width: 760px;
}

.page-head__copy p {
  margin: 0;
  color: rgba(17, 34, 45, 0.68);
  font-size: 1.08rem;
  line-height: 1.6;
}

.page-head__chip {
  width: max-content;
  padding-inline: 0;
  background: transparent !important;
  box-shadow: none !important;
  font-weight: 850;
}

.page-head h1,
.panel h2 {
  margin: 0;
}

.page-head__cta.q-btn.bg-primary {
  width: max-content;
  margin-top: 8px;
  min-height: 62px;
  padding-inline: 26px;
  font-size: 1.05rem;
  font-weight: 900;
  animation: exampleCtaPulse 2.15s ease-in-out infinite;
  box-shadow: 0 16px 34px rgba(245, 66, 145, 0.28) !important;
}

@keyframes exampleCtaPulse {
  0%,
  100% {
    transform: scale(1);
    filter: drop-shadow(0 0 0 rgba(245, 66, 145, 0));
  }

  50% {
    transform: scale(1.035);
    filter: drop-shadow(0 10px 18px rgba(245, 66, 145, 0.28));
  }
}

.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  margin-bottom: 18px;
}

.panel-head h2 {
  font-size: clamp(1.42rem, 1.75vw, 1.88rem);
  line-height: 1.18;
}

.panel-head--compact {
  margin-bottom: 0;
}

.setup-tabs {
  padding: 8px 14px;
  border: 1px solid var(--soz-line);
  border-radius: 30px;
  background: var(--soz-soft-white);
  backdrop-filter: blur(18px);
  box-shadow:
    0 18px 40px rgba(33, 18, 8, 0.04),
    inset 0 1px 0 rgba(255, 255, 255, 0.8);
}

.setup-tabs :deep(.q-tabs__content) {
  gap: 18px;
}

.setup-tabs :deep(.q-tabs__indicator),
.setup-tabs :deep(.q-tab__indicator) {
  display: none;
}

.setup-tabs :deep(.q-tab) {
  min-height: 54px;
  padding: 0 20px;
  border-radius: 999px;
  color: var(--soz-muted);
  transition:
    background-color 0.18s ease,
    box-shadow 0.18s ease,
    color 0.18s ease;
}

.setup-tabs :deep(.q-tab:hover) {
  background: var(--soz-primary-tint);
}

.setup-tabs :deep(.q-tab--active),
.setup-tabs :deep(.q-tab--active:hover) {
  background: var(--soz-menu-gradient);
  color: #ffffff !important;
}

.setup-tabs :deep(.q-tab--active .q-focus-helper) {
  opacity: 0 !important;
}

.setup-tabs :deep(.q-tab--active .q-icon),
.setup-tabs :deep(.q-tab--active .q-tab__label) {
  color: #ffffff !important;
}

.setup-tabs :deep(.q-tab__content) {
  gap: 8px;
}

.setup-tabs :deep(.q-tab__label) {
  font-size: 1.2rem;
}

.setup-panels {
  margin: 18px -34px 0;
  padding: 4px 34px 58px;
  background: transparent;
  overflow: hidden;
}

.setup-panel {
  padding: 0 0 52px;
  overflow: hidden;
}

.setup-panels :deep(.q-panel) {
  width: calc(100% + 68px);
  margin-inline: -34px;
  padding-inline: 34px;
  overflow: hidden;
}

.settings-grid,
.event-grid,
.service-list,
.listing-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}

.feature-toggle-row {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 20px;
}

.feature-toggle {
  display: inline-flex;
  gap: 12px;
  align-items: center;
  min-height: 46px;
  padding: 8px 18px 8px 10px;
  border: 0;
  border-radius: 999px;
  background: #e8ebf0;
  color: rgba(17, 34, 45, 0.72);
  box-shadow: inset 0 0 0 1px rgba(17, 34, 45, 0.06);
  cursor: default;
  font: inherit;
  font-size: 0.95rem;
  font-weight: 800;
}

.feature-toggle--active {
  background: var(--soz-action-gradient);
  color: #fff;
  box-shadow: 0 14px 28px rgba(245, 66, 145, 0.22);
}

.feature-toggle__dot {
  width: 28px;
  height: 28px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.92);
  box-shadow: 0 6px 14px rgba(17, 34, 45, 0.14);
}

.example-settings-note {
  margin: 14px 0 0;
  color: rgba(17, 34, 45, 0.68);
  font-weight: 750;
}

.page-delete-btn.q-btn.bg-negative {
  background: #e23f57 !important;
  box-shadow: 0 12px 24px rgba(226, 63, 87, 0.22) !important;
}

.presence-grid {
  display: block;
}

.presence-editor {
  display: grid;
  gap: 18px;
}

.presence-segment {
  display: grid;
  gap: 14px;
  padding: 18px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 20px;
  background: rgba(255, 255, 255, 0.78);
}

.presence-segment__title {
  color: #151f2d;
  font-size: 15px;
  font-weight: 700;
}

.section-label {
  color: rgba(17, 34, 45, 0.52);
  font-size: 14px;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.upload-group,
.hours-grid {
  display: grid;
  gap: 12px;
}

.hours-row {
  display: grid;
  grid-template-columns: 150px 110px minmax(0, 1fr) minmax(0, 1fr);
  gap: 12px;
  align-items: center;
}

.upload-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 12px;
  align-items: center;
}

.palette-grid {
  display: grid;
  grid-template-columns: repeat(8, minmax(0, 1fr));
  gap: 12px;
}

.palette-card {
  position: relative;
  display: grid;
  gap: 10px;
  padding: 12px;
  border: 2px solid rgba(17, 34, 45, 0.08);
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.85);
  color: inherit;
  text-align: start;
}

.palette-card--active {
  transform: translateY(-3px);
  border-color: #f54291;
  background: #fff;
  box-shadow:
    0 0 0 4px rgba(245, 66, 145, 0.18),
    0 20px 42px rgba(245, 66, 145, 0.22);
}

.palette-card:disabled {
  cursor: not-allowed;
}

.palette-card__check {
  position: absolute;
  top: 8px;
  inset-inline-end: 8px;
  z-index: 1;
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  border-radius: 999px;
  background: #fff;
  color: #f54291;
  font-size: 24px;
  box-shadow: 0 8px 18px rgba(245, 66, 145, 0.28);
}

.palette-card__swatch {
  display: block;
  width: 100%;
  height: 44px;
  border-radius: 12px;
}

.palette-card__name {
  color: #151f2d;
  font-size: 0.94rem;
  font-weight: 600;
}

.example-disabled-form {
  opacity: 0.9;
}

.example-disabled-zone {
  pointer-events: none;
}

.example-disabled-zone :deep(.q-btn) {
  opacity: 0.58;
}

.preview-placeholder-list {
  display: grid;
  gap: 18px;
  min-width: 0;
}

.preview-placeholder-segment {
  display: grid;
  gap: 16px;
  min-height: 270px;
  padding: 20px;
  border: 1px dashed rgba(245, 66, 145, 0.45);
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.54);
}

.preview-placeholder-heading {
  display: inline-flex;
  gap: 12px;
  align-items: center;
  width: max-content;
  max-width: 100%;
  padding: 0;
  border: 0;
  background: transparent;
  color: #151f2d;
  cursor: pointer;
  font: inherit;
  font-size: 1rem;
  font-weight: 800;
  text-align: start;
}

.preview-placeholder-heading__icon {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  flex: 0 0 44px;
  border-radius: 999px;
  background: var(--soz-action-gradient);
  color: #fff;
  box-shadow: 0 12px 24px rgba(245, 66, 145, 0.22);
}

.preview-placeholder-heading__icon :deep(.q-icon) {
  font-size: 22px !important;
  line-height: 1 !important;
}

.preview-placeholder-heading__arrow {
  color: rgba(17, 34, 45, 0.48);
  font-size: 20px;
}

.preview-placeholder-card-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 14px;
}

.preview-placeholder-card-grid--two {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.preview-placeholder-card {
  display: grid;
  gap: 12px;
  min-height: 178px;
  padding: 14px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.76);
  box-shadow: 0 14px 28px rgba(17, 34, 45, 0.06);
}

.preview-placeholder-card__media,
.preview-placeholder-card__line {
  display: block;
  border-radius: 999px;
  background: linear-gradient(90deg, rgba(255, 124, 44, 0.18), rgba(245, 66, 145, 0.16), rgba(129, 69, 255, 0.16));
}

.preview-placeholder-card__media {
  height: 86px;
  border-radius: 12px;
}

.preview-placeholder-card__line {
  width: 100%;
  height: 10px;
}

.preview-placeholder-card__line--strong {
  width: 72%;
  height: 14px;
}

.preview-placeholder-card__line--short {
  width: 48%;
}

.product-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
}

@media (max-width: 1100px) {
  .product-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .palette-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

@media (max-width: 800px) {
  .palette-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .upload-row {
    grid-template-columns: 1fr;
  }

  .hours-row {
    grid-template-columns: minmax(0, 1fr) auto;
  }

  .hours-row .q-field {
    grid-column: 1 / -1;
  }
}

@media (max-width: 760px) {
  .product-grid,
  .preview-placeholder-card-grid--two {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 700px) {
  .setup-page {
    padding-inline: 10px;
  }

  .page-head,
  .panel {
    padding: 20px;
  }

  .page-head__cta.q-btn {
    width: 100%;
  }

  .setup-tabs {
    padding: 6px 38px;
    border-radius: 22px;
  }

  .setup-tabs :deep(.q-tabs__content) {
    gap: 4px;
  }

  .setup-tabs :deep(.q-tab) {
    min-height: 40px;
    padding: 0 8px;
  }

  .setup-tabs :deep(.q-icon) {
    font-size: 18px;
  }

  .setup-tabs :deep(.q-tab__label) {
    font-size: 0.82rem;
    font-weight: 700;
  }

  .setup-tabs :deep(.q-tabs__arrow) {
    z-index: 2;
    min-width: 30px;
    color: var(--soz-ink);
    text-shadow: none;
  }

  .setup-tabs :deep(.q-tabs__arrow--left) {
    inset-inline-start: 4px;
  }

  .setup-tabs :deep(.q-tabs__arrow--right) {
    inset-inline-end: 4px;
  }

  .feature-toggle {
    width: 100%;
  }

  .panel-head {
    align-items: stretch;
    flex-direction: column;
  }

  .panel-head .q-btn {
    width: 100%;
  }

  .presence-segment {
    gap: 10px;
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
  }

  .palette-card {
    padding: 10px;
  }
}
</style>
