function filterValue(value) {
	const normalized = String(value ?? '').trim()

	return normalized || 'not set'
}

export function aiWorkBulkEditTask(filters = {}) {
	return `Research and enrich existing unclaimed Sveevee pages

Complete this task autonomously without a human review step after starting.

Filters to use in AI Works > Bulk Edit:
- City: ${filterValue(filters.city)}
- Neighborhood: ${filterValue(filters.neighborhood)}
- Category key: ${filterValue(filters.category_key)}
- ID from: ${filterValue(filters.id_from)}
- ID to: ${filterValue(filters.id_to)}

Workflow:
1. Open AI Works > Bulk Edit, enter exactly the filters above, and click Load JSON.
2. For every page in the JSON array, research current public business or community information on the web. Prefer the official website and official social profiles. Confirm that each source matches the page name and location.
3. Keep every id unchanged. Keep the result as one valid JSON array, in the same order, without adding or removing rows.
4. Add only information that is clearly confirmed and publicly presented for business or community contact. Useful fields include phone, whatsapp, contact_email, website, address, socials, opening_hours, and a concise factual public_description.
5. Keep socials as one object with facebook, instagram, tiktok, and telegram. Add or change only verified official profile URLs; otherwise keep the existing value.
6. Keep all seven opening_hours entries. Each entry uses weekday, is_open, opens_at, and closes_at. Use 24-hour HH:MM times for open days and null times for closed days. Change hours only when they are verified.
7. Never guess. Leave an existing value unchanged when reliable information cannot be confirmed. Do not add private personal data.
8. Do not copy reviews, protected descriptions, images, logos, or content from Google Maps or another directory. Write descriptions in original factual language.
9. Do not change type, name, category_key, city, or neighborhood unless the task explicitly asks for that correction and the correction is verified.
10. Save the edited array with Validate & save JSON. If validation reports a row and field, correct only the indicated data and retry. Never bypass a claimed or unavailable page.
11. If the screen reports more matches, repeat with ID from set to the shown next ID.
12. Finish by reporting how many pages were updated and which requested fields could not be verified.`
}
