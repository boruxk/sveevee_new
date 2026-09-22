# Nearby community features

`/nearby` is a separate public feed, linked from the desktop navigation and mobile menu. The existing Search page keeps its layout and behavior.

## Current interface

1. Products, services, and events have public detail pages with a **Questions** section. Products retain `/product/{slug}` and their localized URLs; services use `/services/{id}` and events `/events/{id}`. Existing View buttons open the detail page, including from the owner's area. The detail contains public information and one level of question replies. Service website links and event dates, times, addresses, and publisher links remain available. Past events remain readable directly; Nearby still lists upcoming events.
2. Nearby uses compact cards with a linked card surface. Question, ad, and event cards open their corresponding detail pages. Likes, reports, and other nested controls retain their own actions. Search cards and the Search page layout are unchanged.
3. `/questions/{id}` presents the question and discussion in centered content blocks.
4. **Me -> My questions** contains Edit and Resolve/Reopen controls inside the question cards. The detail page retains its author controls too.
5. Question status, owner-reply, and helpful-answer badges use the existing purple pill style through the shared community badge component.
6. The page-banner Follow button uses the same orange styling and height as Contact and Share.
7. Page-banner buttons are smaller and ordered **Contact -> Follow -> Share**, mirrored for Hebrew. The chat action is labelled Contact and keeps its existing chat behavior and visibility rules.
8. **Me -> My subscriptions** has an inline subscription panel with notification toggles and Unfollow. Nearby retains its subscription-management dialog and Following filter; both use the same panel behavior.

On Nearby, choose a city, optional neighborhood, and catalogue topic. The Follow action for an area requires both city and topic. Feed results load 20 at a time. Guests can read public content; writing, liking, following, and reporting require an active user or admin account. Private chats remain separate.

Questions and replies can recommend an existing business page, including an unclaimed business. Recommendations link to that page. Reviews reuse the existing rating system: the business must have an owner, and the reviewer cannot be its owner. Only the author of a local question can mark another person's reply helpful.

## API

All endpoints below have the `/api/v1` prefix and use the existing API response envelope.

| Endpoint | Behavior |
| --- | --- |
| `GET /nearby` | Mixed question/ad/upcoming-event feed; `kind=all|question|ad|event|following`, city, neighborhood, category key, opaque cursor. |
| `GET /questions?mine=1` | Authenticated user's questions, 20 per cursor. |
| `GET /questions/{id}`; `POST /questions`; `PATCH/DELETE /questions/{id}` | Public detail and authorized create/edit/resolve/delete. |
| `GET /products/{slugOrId}` | Existing product detail, now also returning social counts/state. |
| `GET /services/{id}` | Public service detail with image metadata, website link, minimal page identity/address, and social state. Does not load the rest of the business's catalogue. |
| `GET /nearby/events/{id}` | Public event detail, including past events, with minimal page/personal publisher identity and social state. |
| `GET/POST /discussions/{type}/{id}/comments` | Public questions/replies, 30 per cursor; authenticated creation. Thread types: `question`, `ad`, `event`, `product`, `service`. |
| `DELETE /comments/{id}` | Comment author or admin hides a comment. Its replies become inaccessible too. |
| `PUT/DELETE /questions/{id}/helpful/{commentId}` | Local-question author marks/unmarks another person's helpful answer. |
| `GET /social/{type}/{id}`; `PUT/DELETE /social/{type}/{id}/like` | Counts and idempotent likes; all thread types plus `comment`. |
| `GET /social-state?targets[]=product:1&targets[]=service:2` | Batch social state for at most 100 targets; invisible targets omitted. |
| `GET /nearby/page-options?q=...` | Up to 20 public business recommendations, minimum two search characters. |
| `GET/POST /subscriptions`; `PATCH/DELETE /subscriptions/{id}` | Own page/topic subscriptions and notification preferences. |
| `GET /subscriptions/status` | Subscription status for a page or city/topic scope. |
| `POST /social/{type}/{id}/reports` | Report a visible target. |
| `GET /admin/community-reports`; `PATCH /admin/community-reports/{id}` | Admin report list and `dismiss`/`hide` actions, retaining source evidence. |

Comment bodies are limited to 3,000 characters; local questions have a 180-character title and 5,000-character body. Replies use a root `parent_id`; a third nesting level is rejected. The owner-reply badge and owner notifications use the current page owner for products, services, and page events, including after ownership changes.

The product/service question sections expose comment actions. The backend also supports product/service target likes and reports. Service and event detail pages currently use `noindex,follow`; the existing product indexing behavior is unchanged.

## Storage and behavior

Migration `2026_09_18_000100_create_community_features.php` creates `local_questions`, `public_comments`, `social_likes`, `community_subscriptions`, `community_reports`, and `community_notification_receipts`, and adds `community_hidden_at` to ads and events.

The separate migration `2026_09_19_000100_add_community_moderation_to_page_items.php` adds `community_hidden_at` to products and services. The first migration was not rewritten. Both have been applied locally; production migration remains part of a separately authorized deployment.

Hiding content preserves the record and report evidence while removing public access. Public item details and discussion check that the parent page is managed and its owner is active; products and services require a business page. Banned owners, unclaimed pages, hidden content, and hidden parent comments do not expose discussion or reaction endpoints. Page/user deletion still removes media belonging to hidden products, services, ads, and events.

Likes and subscriptions have database uniqueness constraints. Repeating a like does not increment its count twice. Thread writes acquire row locks and recheck visibility. Feed and comment queries use bounded cursor pagination; existing card social state is fetched in batches. Imported business pages can be recommended or followed, but do not become feed posts.

New questions, ads, and upcoming events dispatch `NotifyCommunitySubscribers` through the existing Laravel queue after commit. The worker processes followers in chunks, honors notification opt-out, and deduplicates overlapping subscriptions and retries. Existing content is not replayed as a historical notification campaign. Replies, helpful answers, likes, and followed activity use the existing private notification center. Like notifications are grouped per recipient/target/day. No additional email delivery is enabled.

Public writes use server-side authorization, text validation, and a shared limit of 20 operations/minute and 150/hour per account. Reading My questions or My subscriptions does not consume this write allowance. Each account can have up to 100 subscriptions. All four interface locales are supported.

## Release requirements

Run both outstanding migrations before serving the updated backend/frontend. Restart existing queue workers after updating the release so they load the new job and observer code. No new timer or third-party service is required. Production deployment remains a separate action after the user's push and explicit deployment request.

## Verification

- `backend/tests/Feature/CommunityFeaturesApiTest.php`: feed cursor stability and bounded queries, public/private access, comments, helpful answers, likes, subscription ownership/opt-out/cap, notifications, reports, rate limits, banned accounts, and moderated-media cleanup.
- `backend/tests/Feature/ItemDiscussionApiTest.php`: service-detail hydration bound, product/service questions and owner replies after reassignment, notification paths, likes, parent visibility, reports/moderation, past-event access, and hidden item media cleanup.
- The two feature files passed together: **18 tests, 327 assertions**. Selected existing search/product/service/event/deletion regressions passed separately: **18 tests, 383 assertions**.
- All **94 frontend Node tests** pass, including `frontend/tests/communityStore.test.mjs`. Full ESLint, the four-locale i18n audit, and the production build also pass.
- Local mocked-browser checks cover product/service/event View actions and Questions, threaded replies, guest sign-in, error recovery, Nearby card navigation without nested-action interception, centered question details, in-card My questions controls, banner button order and spacing in English/Hebrew, and the inline My subscriptions panel. Desktop/mobile checks include all four locales; API authorization and database behavior are covered separately by the backend tests.