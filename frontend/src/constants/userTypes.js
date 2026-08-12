const LOCALE_KEYS = ['en', 'he', 'ru', 'fr']

function labels(en, he, ru, fr) {
	return { en, he, ru, fr }
}

export const USER_TYPE_GROUPS = [
	{
		key: 'professionals',
		labels: labels('Professionals', 'בעלי מקצוע', 'Профессионалы', 'Professionnels'),
		color: '#f97316',
		soft: 'rgba(249, 115, 22, 0.14)',
		items: [
			{ key: 'cleaning_polish', labels: labels('Cleaning & Polish', 'ניקיון ופוליש', 'Уборка и полировка', 'Nettoyage et polissage') },
			{ key: 'drywall', labels: labels('Drywall', 'עבודות גבס', 'Гипсокартон', 'Plaques de platre') },
			{ key: 'moving', labels: labels('Moving', 'הובלות', 'Переезды', 'Demenagement') },
			{ key: 'security', labels: labels('Security', 'מיגון', 'Безопасность', 'Securite') },
			{ key: 'interior_design', labels: labels('Interior Design', 'עיצוב פנים', 'Дизайн интерьера', 'Design interieur') },
			{ key: 'car_accessories', labels: labels('Car Accessories', 'רכב אביזרים', 'Автоаксессуары', 'Accessoires auto') },
			{ key: 'shuttles', labels: labels('Shuttles', 'הסעות', 'Трансферы', 'Navettes') },
			{ key: 'garages', labels: labels('Garages', 'מוסכים', 'Автосервисы', 'Garages') },
			{ key: 'leasing', labels: labels('Leasing', 'ליסינג', 'Лизинг', 'Leasing') },
			{ key: 'car_rental', labels: labels('Car Rental', 'השכרת רכב', 'Аренда авто', 'Location de voiture') },
			{ key: 'building_contractors', labels: labels('Building Contractors', 'קבלני בניין', 'Строительные подрядчики', 'Entrepreneurs du batiment') },
			{ key: 'renovation', labels: labels('Renovation', 'שיפוצים', 'Ремонт', 'Renovation') },
			{ key: 'electricians', labels: labels('Electricians', 'חשמלאים', 'Электрики', 'Electriciens') },
			{ key: 'gypsum', labels: labels('Gypsum', 'גבס', 'Гипс', 'Platre') },
			{ key: 'sealing_roofing', labels: labels('Sealing & Roofing', 'איטום וזיפות', 'Герметизация и кровля', 'Etancheite et toiture') },
			{ key: 'event_production', labels: labels('Event Production', 'ארגון והפקה', 'Организация мероприятий', 'Organisation evenementielle') },
			{ key: 'photo_video', labels: labels('Photo & Video', 'צילום ועריכה', 'Фото и видео', 'Photo et video') },
			{ key: 'venues', labels: labels('Venues', 'אולמות וגנים', 'Залы и площадки', 'Salles et lieux') },
			{ key: 'medical_massage', labels: labels('Medical Massage', 'עיסוי רפואי', 'Медицинский массаж', 'Massage medical') },
			{ key: 'personal_trainer', labels: labels('Personal Trainer', 'מאמן כושר אישי', 'Персональный тренер', 'Coach sportif') },
			{ key: 'alternative_medicine', labels: labels('Alternative Medicine', 'רפואה משלימה', 'Альтернативная медицина', 'Medecine alternative') },
			{ key: 'nutrition', labels: labels('Nutrition', 'דיאטה ותזונה', 'Питание', 'Nutrition') },
			{ key: 'private_tutors', labels: labels('Private Tutors', 'מורים פרטיים', 'Частные преподаватели', 'Professeurs particuliers') },
			{ key: 'music_lessons', labels: labels('Music Lessons', 'לימודי נגינה', 'Музыкальные уроки', 'Cours de musique') },
			{ key: 'kids_activities', labels: labels('Kids Activities', 'הפעלות ילדים', 'Детские активности', 'Activites enfants') },
			{ key: 'language_lessons', labels: labels('Language Lessons', 'לימודי שפה', 'Языковые уроки', 'Cours de langue') },
			{ key: 'cosmetics', labels: labels('Cosmetics', 'קוסמטיקה', 'Косметика', 'Cosmetiques') },
			{ key: 'beauticians', labels: labels('Beauticians', 'קוסמטיקאיות', 'Косметологи', 'Estheticiennes') },
			{ key: 'hair_salons', labels: labels('Hair & Salons', 'עיצוב שיער ומספרות', 'Парикмахерские', 'Coiffure et salons') },
			{ key: 'beauty_salons', labels: labels('Beauty Salons', 'מכוני יופי', 'Салоны красоты', 'Instituts de beaute') },
			{ key: 'translation', labels: labels('Translation', 'תרגום', 'Перевод', 'Traduction') },
			{ key: 'courier', labels: labels('Courier', 'שליחויות', 'Курьер', 'Livraison') },
			{ key: 'professional_guidance', labels: labels('Professional Guidance', 'ייעוץ והכוונה מקצועית', 'Профессиональная консультация', 'Orientation professionnelle') },
			{ key: 'coaching', labels: labels('Personal / Business Coaching', 'אימון אישי / עסקי', 'Личный / бизнес-коучинг', 'Coaching personnel / business') },
			{ key: 'catering', labels: labels('Catering', 'קייטרינג', 'Кейтеринг', 'Traiteur') },
			{ key: 'grocery_food', labels: labels('Grocery & Food Stores', 'רשתות מזון ומכולת', 'Продуктовые магазины', 'Epiceries et alimentation') },
			{ key: 'fish_restaurants', labels: labels('Fish Restaurants', 'מסעדות דגים', 'Рыбные рестораны', 'Restaurants de poisson') },
			{ key: 'fast_food', labels: labels('Fast Food', 'מסעדות מזון מהיר', 'Фастфуд', 'Restauration rapide') },
			{ key: 'sewage_contractors', labels: labels('Sewage Contractors', 'קבלני ביוב', 'Подрядчики по канализации', 'Entrepreneurs egouts') },
			{ key: 'waste_recycling', labels: labels('Waste & Recycling', 'פינוי ומחזור פסולת', 'Вывоз и переработка отходов', 'Dechets et recyclage') },
			{ key: 'industrial_design', labels: labels('Industrial Design', 'עיצוב תעשייתי', 'Промышленный дизайн', 'Design industriel') },
			{ key: 'prefab_building', labels: labels('Prefab Building', 'בניה מתועשת', 'Модульное строительство', 'Construction prefabriquee') },
			{ key: 'pest_control', labels: labels('Pest Control', 'הדברה וריסוס', 'Дезинсекция', 'Desinsectisation') },
			{ key: 'computer_technician', labels: labels('Computer Technician', 'טכנאי מחשבים', 'Компьютерный техник', 'Technicien informatique') },
			{ key: 'appliance_technician', labels: labels('Appliance Technician', 'טכנאי מוצרי חשמל', 'Техник бытовой техники', 'Technicien electromenager') },
			{ key: 'pet_grooming', labels: labels('Pet Grooming', 'מספרות לחיות', 'Груминг животных', 'Toilettage animaux') },
			{ key: 'veterinarians', labels: labels('Veterinarians', 'וטרינרים', 'Ветеринары', 'Veterinaires') },
			{ key: 'dog_training', labels: labels('Dog Training', 'אילוף כלבים', 'Дрессировка собак', 'Dressage chiens') }
		]
	},
	{
		key: 'entertainers',
		labels: labels('Entertainers', 'אומני בידור', 'Артисты и ведущие', 'Artistes et animation'),
		color: '#e11d48',
		soft: 'rgba(225, 29, 72, 0.13)',
		items: [
			{ key: 'dj', labels: labels('DJ', 'DJ', 'DJ', 'DJ') },
			{ key: 'musician_singer', labels: labels('Musician / Singer', 'מוזיקאי / זמר', 'Музыкант / певец', 'Musicien / chanteur') },
			{ key: 'magician', labels: labels('Magician', 'קוסם', 'Фокусник', 'Magicien') },
			{ key: 'kids_entertainer', labels: labels('Kids Entertainer', 'ליצן / מפעיל ילדים', 'Детский артист', 'Animateur enfants') },
			{ key: 'event_host', labels: labels('Event Host', 'מנחה אירועים', 'Ведущий мероприятий', 'Animateur evenementiel') },
			{ key: 'dancer_band', labels: labels('Dancer / Band', 'רקדן / להקה', 'Танцор / группа', 'Danseur / groupe') },
			{ key: 'comedian', labels: labels('Comedian', 'סטנדאפיסט', 'Стендап-комик', 'Humoriste') },
			{ key: 'actor', labels: labels('Actor', 'שחקן', 'Актер', 'Acteur') },
			{ key: 'street_performer', labels: labels('Street Performer', 'אמן רחוב', 'Уличный артист', 'Artiste de rue') },
			{ key: 'karaoke', labels: labels('Karaoke', 'קריוקי', 'Караоке', 'Karaoke') },
			{ key: 'event_attractions', labels: labels('Event Attractions', 'אטרקציות לאירועים', 'Аттракционы для мероприятий', 'Attractions evenementielles') }
		]
	},
	{
		key: 'creators',
		labels: labels('Creators', 'יוצרים', 'Креаторы', 'Createurs'),
		color: '#9333ea',
		soft: 'rgba(147, 51, 234, 0.13)',
		items: [
			{ key: 'photographer', labels: labels('Photographer', 'צלם', 'Фотограф', 'Photographe') },
			{ key: 'video_editor', labels: labels('Video / Editing', 'וידאו / עריכה', 'Видео / монтаж', 'Video / montage') },
			{ key: 'graphic_designer', labels: labels('Graphic Designer', 'מעצב גרפי', 'Графический дизайнер', 'Graphiste') },
			{ key: 'content_writer', labels: labels('Content Writer', 'כותב תוכן', 'Автор контента', 'Redacteur de contenu') },
			{ key: 'illustrator', labels: labels('Illustrator', 'מאייר', 'Иллюстратор', 'Illustrateur') },
			{ key: 'artist', labels: labels('Artist', 'אמן', 'Художник', 'Artiste') },
			{ key: 'fashion_designer', labels: labels('Fashion Designer', 'מעצב אופנה', 'Дизайнер моды', 'Createur de mode') },
			{ key: 'handmade', labels: labels('Handmade Creator', 'יוצר בעבודת יד', 'Ручная работа', 'Createur fait main') },
			{ key: 'jewelry', labels: labels('Jewelry', 'תכשיטים', 'Украшения', 'Bijoux') },
			{ key: 'personalized_gifts', labels: labels('Personalized Gifts', 'מתנות אישיות', 'Персональные подарки', 'Cadeaux personnalises') }
		]
	}
]

export const USER_TYPE_KEYS = USER_TYPE_GROUPS.flatMap((group) => (
	group.items.map((item) => `${group.key}.${item.key}`)
))

function normalizeLocale(locale) {
	const key = String(locale || 'en').split('-')[0]

	return LOCALE_KEYS.includes(key) ? key : 'en'
}

function localized(localizedLabels, locale) {
	return localizedLabels[normalizeLocale(locale)] || localizedLabels.en
}

export function buildUserTypeSelectOptions(locale = 'en') {
	return USER_TYPE_GROUPS.flatMap((group) => {
		const groupLabel = localized(group.labels, locale)

		return [
			{
				label: groupLabel,
				value: `${group.key}.__group`,
				disable: true,
				group: true,
				color: group.color
			},
			...group.items.map((item) => ({
				label: localized(item.labels, locale),
				value: `${group.key}.${item.key}`,
				itemKey: item.key,
				groupKey: group.key,
				groupLabel,
				color: group.color,
				soft: group.soft
			}))
		]
	})
}

export function userTypeMeta(value, locale = 'en') {
	for (const group of USER_TYPE_GROUPS) {
		const item = group.items.find((candidate) => `${group.key}.${candidate.key}` === value)

		if (item) {
			return {
				label: localized(item.labels, locale),
				value,
				itemKey: item.key,
				groupKey: group.key,
				groupLabel: localized(group.labels, locale),
				color: group.color,
				soft: group.soft
			}
		}
	}

	return null
}
