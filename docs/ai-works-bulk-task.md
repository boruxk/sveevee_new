# ChatGPT Work Task: Research and Create Unclaimed Pages

Use this task autonomously without a human review step after it has been started.

## Objective

Research lawful, publicly available information for local businesses or communities and create informational, unclaimed Sveevee pages with AI Works > Bulk Import.

## Workflow

1. Read the task's requested city, neighborhood, category, page type, and number of pages.
2. Search the web for matching active businesses or communities. Prefer official websites and official social profiles. Confirm each result by name and location.
3. Do not copy reviews, protected descriptions, images, logos, or directory text. Do not collect private personal data.
4. Build one JSON array with at most 1,000 rows. Every row must contain `type`, `name`, `category_key`, and `city`. `neighborhood` is optional.
5. Add only verified public business information such as `phone`, `contact_email`, `website`, street, number, official social profiles, opening hours, and a short original factual description. Put social URLs under `socials` using `facebook`, `instagram`, `tiktok`, and `telegram`. For `opening_hours`, include all seven weekdays, use 24-hour `HH:MM` times, and use `null` times when `is_open` is `false`. Never invent missing values.
6. Open AI Works > Bulk Import, choose JSON, paste the array, and click Check & create pages.
7. Sveevee automatically skips invalid rows and exact duplicates. Do not retry skipped rows unless the task explicitly asks for corrected data.
8. Complete the task with the number created, duplicate, and invalid, plus a short list of information that could not be verified.

Do not include source URLs, source-check dates, images, or logos in the import. Sveevee stores only valid created pages.

## Goal

Research the requested number of real local businesses or communities on the public internet and create basic unclaimed Sveevee pages through **AI Works > Pages > Bulk Import**.

## Workflow

1. Read the current AI Works assignment. Treat its page type, location, category, and target count as requirements.
2. Search the public internet. Prefer the entity's official website or official social profile, then reputable public business directories.
3. Use only public facts intended for the business or community activity. Do not copy reviews, protected descriptions, images, logos, or private personal information.
4. Prepare one JSON object per entity. Every object must contain its own `type`, `name`, `category_key`, and `city`. `neighborhood` is optional and must be omitted or empty when it cannot be verified. Put verified official profiles in `socials`, and add `opening_hours` with all seven weekdays only when the hours were verified.
5. Do not include a palette, source URL, source date, logo, image, module, chat, or rating fields. Sveevee chooses the palette and keeps all modules disabled.
6. Remove obvious duplicates from the prepared list, then paste one JSON array with no more than 1,000 objects into Bulk Import.
7. Click **Check & create pages** once. Sveevee automatically rejects invalid rows and exact duplicates and creates every valid row.
8. Do not manually repair or resubmit rejected rows from that import. If the assignment still needs more pages, research replacement entities and run a new import containing only those new rows.
9. Finish by reporting the import summary: created, duplicate skipped, and invalid skipped.

## JSON Format

```json
[
  {
    "type": "business",
    "name": "Example Electric",
    "public_description": "Electrical installation and repair services in Ramat Aviv.",
    "category_key": "professionals.electricians",
    "city": "Tel Aviv",
    "neighborhood": "Ramat Aviv",
    "street": "Example Street",
    "number": "10",
    "phone": "03-0000000",
    "contact_email": "",
    "website": "https://example.com",
    "socials": {
      "facebook": "https://www.facebook.com/example",
      "instagram": "https://www.instagram.com/example",
      "tiktok": null,
      "telegram": null
    },
    "opening_hours": [
      { "weekday": "sunday", "is_open": false, "opens_at": null, "closes_at": null },
      { "weekday": "monday", "is_open": true, "opens_at": "09:00", "closes_at": "17:00" },
      { "weekday": "tuesday", "is_open": true, "opens_at": "09:00", "closes_at": "17:00" },
      { "weekday": "wednesday", "is_open": true, "opens_at": "09:00", "closes_at": "17:00" },
      { "weekday": "thursday", "is_open": true, "opens_at": "09:00", "closes_at": "17:00" },
      { "weekday": "friday", "is_open": true, "opens_at": "09:00", "closes_at": "13:00" },
      { "weekday": "saturday", "is_open": false, "opens_at": null, "closes_at": null }
    ]
  }
]
```

Use `business` or `community` as `type`. Use canonical Sveevee category keys and location names. Never guess an unknown city, neighborhood, category, phone number, website, or address.
