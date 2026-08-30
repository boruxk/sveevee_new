# ChatGPT Work Task: Research and Enrich Unclaimed Pages

Use this task autonomously without a human review step after it has been started.

## Filters

- City: `[CITY OR NOT SET]`
- Neighborhood: `[NEIGHBORHOOD OR NOT SET]`
- Category key: `[CATEGORY KEY OR NOT SET]`
- ID from: `[ID OR NOT SET]`
- ID to: `[ID OR NOT SET]`

## Workflow

1. Open AI Works > Bulk Edit, enter exactly the filters from the task, and click Load JSON.
2. For every page in the JSON array, research current public business or community information on the web. Prefer the official website and official social profiles. Confirm that each source matches the page name and location.
3. Keep every `id` unchanged. Keep the result as one valid JSON array, in the same order, without adding or removing rows.
4. Add only confirmed public business or community information. Useful fields include `phone`, `whatsapp`, `contact_email`, `website`, `address`, `socials`, `opening_hours`, and a concise factual `public_description`.
5. Keep `socials` as one object with `facebook`, `instagram`, `tiktok`, and `telegram`. Add or change only verified official profile URLs; otherwise keep the existing value.
6. Keep all seven `opening_hours` entries. Each entry uses `weekday`, `is_open`, `opens_at`, and `closes_at`. Use 24-hour `HH:MM` times for open days and `null` times for closed days. Change hours only when they are verified.
7. Never guess. Leave an existing value unchanged when reliable information cannot be confirmed. Do not add private personal data.
8. Do not copy reviews, protected descriptions, images, logos, Google Maps text, or text from another directory. Write descriptions in original factual language.
9. Do not change `type`, `name`, `category_key`, city, or neighborhood unless the task explicitly requests that correction and the correction is verified.
10. Click Validate & save JSON. If validation reports a row and field, correct only the indicated data and retry. Never bypass a claimed or unavailable page.
11. If more than 1,000 pages match, continue with the next ID shown by Sveevee and repeat.
12. Complete the task with the number of updated pages and a short list of requested fields that could not be verified.

Sveevee validates every row before saving. If one row fails, no page in that JSON batch is changed.
