export const presencePalettes = [
	{
		key: 'amber-dawn',
		nameKey: 'palettes.amberDawn',
		accent: '#f08a4b',
		surface: '#fff5eb',
		hero: 'linear-gradient(135deg, #fff3e5 0%, #ffd3b2 100%)',
		ink: '#1d2430',
		muted: '#64584f'
	},
	{
		key: 'olive-mist',
		nameKey: 'palettes.oliveMist',
		accent: '#718355',
		surface: '#f4f5ee',
		hero: 'linear-gradient(135deg, #f3f5ea 0%, #d8e3c4 100%)',
		ink: '#1e2520',
		muted: '#596054'
	},
	{
		key: 'sea-glass',
		nameKey: 'palettes.seaGlass',
		accent: '#2f8f9d',
		surface: '#eef9fa',
		hero: 'linear-gradient(135deg, #eff9fb 0%, #b9e4e7 100%)',
		ink: '#17252a',
		muted: '#587176'
	},
	{
		key: 'berry-ink',
		nameKey: 'palettes.berryInk',
		accent: '#b24c63',
		surface: '#fff1f4',
		hero: 'linear-gradient(135deg, #fff1f4 0%, #f7c7d1 100%)',
		ink: '#241824',
		muted: '#66505c'
	},
	{
		key: 'midnight-copper',
		nameKey: 'palettes.midnightCopper',
		accent: '#d97745',
		surface: '#f8f1eb',
		hero: 'linear-gradient(135deg, #202833 0%, #d97745 100%)',
		ink: '#151f2d',
		muted: '#6c665f'
	},
	{
		key: 'sunset-cream',
		nameKey: 'palettes.sunsetCream',
		accent: '#ff7d5c',
		surface: '#fff6ef',
		hero: 'linear-gradient(135deg, #fff5ef 0%, #ffd8c7 100%)',
		ink: '#1a202c',
		muted: '#71645a'
	},
	{
		key: 'forest-linen',
		nameKey: 'palettes.forestLinen',
		accent: '#4f7a5a',
		surface: '#f5f4ed',
		hero: 'linear-gradient(135deg, #f7f4ed 0%, #d8ddc4 100%)',
		ink: '#1e241f',
		muted: '#64685f'
	},
	{
		key: 'sky-sand',
		nameKey: 'palettes.skySand',
		accent: '#4b79a1',
		surface: '#f2f7fb',
		hero: 'linear-gradient(135deg, #f3f7fb 0%, #d8e7f2 100%)',
		ink: '#182430',
		muted: '#5a6670'
	},
	{
		key: 'plum-sand',
		nameKey: 'palettes.plumSand',
		accent: '#8f5d8c',
		surface: '#faf3f8',
		hero: 'linear-gradient(135deg, #faf2f7 0%, #e6cfe0 100%)',
		ink: '#231b26',
		muted: '#665d67'
	},
	{
		key: 'charcoal-rose',
		nameKey: 'palettes.charcoalRose',
		accent: '#d86c7a',
		surface: '#fbf1f2',
		hero: 'linear-gradient(135deg, #262a31 0%, #d86c7a 100%)',
		ink: '#171c25',
		muted: '#6c6366'
	}
]

const legacyPaletteMap = {
	sunset: 'sunset-cream',
	olive: 'olive-mist',
	ink: 'midnight-copper'
}

export function findPresencePalette(key) {
	const normalizedKey = legacyPaletteMap[key] || key
	return presencePalettes.find((palette) => palette.key === normalizedKey) || presencePalettes[0]
}
