# Pro plans: Private Pro and Business Pro

There are two account subscription plans: **Private Pro, 19 ILS/month** (`1900` agorot), and **Business Pro, 49 ILS/month** (`4900` agorot). Private Pro requires no business page. Business Pro covers **one selected, currently owned business page**, plus the account's private ads. The admin can edit each plan's offer independently for **new contracts**. Existing subscriptions keep their agreed price; the browser submits the displayed price and the backend requires consent again if it changed.

An account has one subscription at a time. Cancellation stops renewal while preserving the paid month, so another plan can be selected only after the paid period ends. Prorated upgrades/downgrades are not implemented. An unfinished checkout also reserves its original plan and price; the UI can resume that checkout but does not silently replace it with another plan.

There is no public launch in this change. Existing free features stay free. A future multi-page package can extend `plan_key` / `included_pages`; no extra-page pricing or extra-page entitlement exists yet.

## Local preview

- Login: `pro@sveevee.local`, local password `password`.
- Open the Pro plans section in your profile (`/profile#business-pro`). `/business-pro` redirects there for existing links.
- The local account has a claimed `Business Pro Test` page and **free local test access to all implemented features from both Private Pro and Business Pro**. No payment is required. This access also covers implemented features that are disabled or still drafts, while unfinished features remain unavailable.
- This override requires `APP_ENV=local`, `BUSINESS_PRO_LOCAL_TESTER_ACCESS=true` (the default), and the server-controlled dedicated tester flag on an active account. An admin role or tester email alone does not grant it. It never grants access in testing, staging or production environments.
- The Pro panel labels this as local test access. Its subscription and payment badges still show the actual billing state, including a pending checkout. The override does not create or mark subscriptions, receipts or payments as paid; checkout, verification and cancellation remain available for sandbox billing tests.
- The profile shows both plan offers with purchase buttons. Private Pro needs no page; Business Pro requires a selected business page. Monthly consent and payment take place in the dialog; paid accounts can manage their subscription there. During private rollout, plan offers are visible only to admins and the dedicated tester. Paid feature controls remain visible to ordinary users, disabled with an explanation when unavailable.
- Admin: Pro plans tab contains separate Private Pro and Business Pro prices, subscriptions, payments and feature controls.
- The four interface languages are Hebrew, English, Russian and French.

During private rollout, only admins and dedicated DB-marked testers can read plan offers and feature lists. The server controls visibility through the authenticated user payload; registration and profile updates cannot grant this permission. A future public rollout allows ordinary users to read published offers, while sandbox checkout still requires an admin or dedicated tester. Unpublished future features remain restricted to preview accounts. The tester flag and payment status are not mass-assignable from normal profile/page requests. Ordinary accounts still require the appropriate verified paid entitlement.

## Featured ads

The first implemented feature, `featured_ads`, is registered enabled and published by the Private Pro migration. Both plans allow the account's private ads to be featured; Business Pro additionally allows business ads on its selected, currently owned page. Community ads and other business pages do not qualify. The existing free ad creation flow remains available.

The creation/editing control stays visible and explains the required paid plan when locked. Server-side writes validate entitlement independently of the control. Public highlighting and ordering also recheck a current paid receipt, matching plan/environment/terminal, ownership, account status and the feature's admin switch. Expiry or disabling the feature removes the paid presentation without deleting the ad or its saved preference. For the dedicated local tester, the local override replaces the paid receipt and feature-switch requirements for implemented features. It allows private ads and business ads on any currently owned, claimed business page; community ads, other owners' pages and normal moderation exclusions remain ineligible.

Search discovery preserves neighborhood, city and remaining-results priority; eligible featured ads lead only within their location tier. Filtered search keeps its filters and promotes matching featured ads ahead of the ordinary result groups. Nearby keeps its profile location, category and subscription filters; eligible featured ads lead, followed by the existing activity ordering (including new visible replies to questions). Within each group the existing date and ID tie-breakers remain. If featured eligibility changes while loading more results, the cursor is refreshed with a notice instead of silently skipping or repeating cards.

`GET /api/v1/business-pro` returns `offers` for both plans, per-offer `can_checkout`, `can_resume`, and `features`, plus `subscription.plan_key` and safe `pending_payment`/`pending_plan_key` metadata. Its `local_test_access` flag identifies the dedicated local override. Feature snapshots also carry `local_test_access`; `available` can be true for an implemented feature under the override even when the real global `enabled` switch is false. The legacy `offer` field remains the Business Pro offer. Checkout accepts `plan_key: private_pro|business_pro` (default Business); Private Pro omits `page_id`, while Business requires its selected page. Admin offer GET accepts `?plan_key=private_pro`; PATCH accepts `plan_key` alongside `amount_minor`. Payment rows retain their purchased `plan_key` even after a later subscription change.

## Cardcom verification on 2026-09-23

Primary references:

- [Official shared test terminal](https://support.cardcom.solutions/hc/he/articles/360002688814)
- [JSON v11 OpenAPI](https://secure.cardcom.solutions/swagger/v11/swagger.json)
- [LowProfile and payment confirmation](https://cardcomapi.zendesk.com/hc/he/articles/28448202810514)
- [Token charging](https://cardcomapi.zendesk.com/hc/he/articles/28452352778770)
- [API key administration](https://support.cardcom.solutions/hc/he/articles/38687626381970)

Cardcom publishes terminal `1000` and states that its test transactions do not actually debit the card. The v11 documented API base is `https://secure.cardcom.solutions/api/v11`. A different hostname alone does not make a transaction a test; this client enforces terminal/environment consistency and requires an additional explicit production flag.

**Test access now works:** on 2026-09-23 Cardcom support confirmed that the test article contained an incorrect API name and supplied its replacement. The minimal `POST https://secure.cardcom.solutions/api/v11/LowProfile/Create` probe at **10:58:45 UTC** used terminal `1000`, amount `1`, `Operation: ChargeOnly`, and public HTTPS success/failure URLs. It returned **HTTP 200**, `ResponseCode: 0`, `Description: OK`, and a hosted payment URL. No API password, document, tokenization or recurring options were sent in this minimal probe. The earlier 2026-09-22 response was HTTP 401 / code 603 using the incorrect published credentials.

The real application payload (`ChargeAndCreateToken`, 49 ILS, local browser return and webhook URLs) also returned a hosted checkout successfully. `GetLpResult` for the unpaid checkout returned code **5119** (pending or incomplete), matching terminal, payment reference and LowProfile ID, with no transaction or token. A checkout for the local Pro tester was then created through the billing service. Both verification and a locally invoked webhook handler fetched the real Cardcom receipt and retained `pending`, even when the callback body claimed success; no paid access was granted. Earlier failed setup records are preserved.

The **local** test payment button is enabled for the private preview. No card was submitted or payment completed. Successful payment, actual token creation/charging and Cardcom-delivered webhook notification still require end-to-end testing. In particular, Cardcom cannot call the developer's loopback webhook; the handler test above is not evidence of provider delivery. Production charging and automatic renewals remain disabled, and no live deployment was performed.

Shared test credentials are not committed. Local `.env` contains the API name corrected by Cardcom support. Do not replace it with real merchant credentials for local testing. API-password-dependent operations have not been tested.

## Configuration

```dotenv
BUSINESS_PRO_ROLLOUT=private
BUSINESS_PRO_LOCAL_TESTER_ACCESS=true
BUSINESS_PRO_BILLING_ENABLED=false
BUSINESS_PRO_RENEWALS_ENABLED=false
CARDCOM_ENV=sandbox
CARDCOM_TERMINAL_NUMBER=1000
CARDCOM_API_NAME=your-working-test-api-name
CARDCOM_API_PASSWORD=your-working-test-api-password
CARDCOM_PRODUCTION_ENABLED=false
BUSINESS_PRO_FRONTEND_URL=http://127.0.0.1:5178
CARDCOM_WEBHOOK_URL=http://127.0.0.1:8000/api/v1/billing/cardcom/webhook
CARDCOM_ALLOW_LOCAL_CALLBACKS=true
CARDCOM_AUTO_RECURRING_TERMINAL=false
```

Local HTTP callbacks are allowed only with the explicit flag, terminal 1000, and a local/testing application environment. Cardcom cannot reach a loopback webhook on the developer's computer. After a local hosted checkout, the return page independently asks the backend to fetch and verify the receipt; use a public HTTPS test deployment for the actual webhook test. Production rejects local callbacks even if that flag was accidentally retained.

The configuration example above keeps billing disabled by default. The local `.env` now has `BUSINESS_PRO_BILLING_ENABLED=true` after the successful test-access verification. Set `BUSINESS_PRO_LOCAL_TESTER_ACCESS=false` when testing payment-dependent feature activation, expiry or cancellation; this restores the normal receipt-based entitlement checks for the local tester. This switch defaults to true but is effective only in the local application environment and does not enable payments or renewals. To test automatic renewal processing explicitly enable `BUSINESS_PRO_RENEWALS_ENABLED`; it defaults off. Neither billing switch is changed by an admin editing the price.

The client has no arbitrary API base URL and never forwards PAN/CVV data. Card entry happens on Cardcom's hosted page. Tokens are encrypted at rest and excluded from all public/admin API responses. API passwords are configured for later provider operations; the implemented v11 creation, receipt and token endpoints authenticate with terminal and API name as documented.

## Payment lifecycle

1. Authenticated eligible account chooses its plan, selects a claimed business page for Business Pro, and consents to the displayed monthly amount.
2. The backend validates ownership, the server price and callback configuration. It serializes checkout creation on the account and persists a payment snapshot.
3. `LowProfile/Create` uses `ChargeAndCreateToken`; the returned Cardcom HTTPS URL opens the hosted page.
4. The webhook or authenticated return verification calls `LowProfile/GetLpResult`. The return URL and webhook payload themselves never grant access.
5. Paid subscription access requires a successful charge, matching terminal/order/amount/currency/operation and non-refund receipt. A paid period is recorded once. Cardcom's actual field spellings include `TranzactionInfo` and `TranzactionId`. The dedicated local feature override is separate from this billing lifecycle.
6. A valid token is encrypted for renewal. If token creation fails after a successful first charge, the paid month is honored and automatic renewal is disabled.
7. At renewal, the server uses the contract's saved amount and one immutable `ExternalUniqTranId` for that billing period. Unknown network outcomes are reconciled through `GetTransactionByExternalUniqTran`; they never create another charge attempt with a new ID. A declined renewal is not retried automatically.
8. Cancellation stops future renewals and retains access through the confirmed paid period. Expiry, deletion, banning or environment mismatch removes access. Losing the selected business page also removes Business Pro access; Private Pro has no page ownership requirement. A late payment after ownership changes is recorded for admin review without enabling another owner's page or scheduling further charges.

The Laravel schedule invokes `business-pro:bill` every five minutes to process receipts and accounts whose **individual monthly renewal date** is due. This is not a five-minute charge cycle. Its global lock and database/idempotency protections prevent overlapping billing attempts. The command refuses to charge when billing/renewals are disabled. Very overdue renewals (more than seven days) require a fresh customer checkout instead of collecting accumulated back charges.

```sh
php artisan business-pro:bill --limit=100
```

The admin payment table exposes safe failure codes for unresolved cases. Do not manually grant paid status or restart an unknown charge with a new ID. Provider refund/reversal automation is outside this foundation; cancellation controls future monthly charges, not refunds.

## Adding a future Pro feature

Add a code definition to `config/business_pro.php`:

```php
'features' => [
    'your_feature' => [
        'implemented' => true,
        'plans' => ['private_pro', 'business_pro'], // Omit for Business-only features.
        'labels' => ['he' => '...', 'en' => '...', 'ru' => '...', 'fr' => '...'],
        'descriptions' => ['he' => '...', 'en' => '...', 'ru' => '...', 'fr' => '...'],
        'sort_order' => 10,
    ],
],
```

Run `php artisan business-pro:sync-features`. This creates a **disabled draft** DB row and preserves existing admin settings. The admin can enable an implemented draft for private testing; the global rollout remains private. The dedicated local tester can exercise implemented registered features under the local override, including disabled drafts from either plan. A database row without implemented code cannot unlock a feature, even for that tester. The implemented Featured ads feature is seeded enabled and published; future feature definitions still start as drafts.

For page-scoped feature endpoints use `auth:sanctum` plus `business-pro.feature:your_feature` on a bound `{page}` route, or call `BusinessProEntitlementService::assertFeature($user, $page, 'your_feature')` inside the service. Apply the check to reads, writes and background jobs as appropriate; CSS disabling alone is not protection.

Render paid controls visibly but disabled with an explanatory hover/focus hint when locked. Use `BusinessProFeature.vue` and the authoritative page-specific feature snapshot from `GET /api/v1/business-pro/pages/{page}`. Never rely on UI disabling as the entitlement check. For public-facing output of a future feature, explicitly gate the output too; don't add draft data to general page payloads. Use this same check whenever ownership or paid-through dates can have changed.

## Deployment later, after push and explicit approval

On 2026-09-23, the dedicated live account `pro@sveevee.local` was created using the existing production-safe command. It has the ordinary user role and the server-controlled tester flag, with no business page, subscription or payment. Live billing remains disabled and this account has preview access only; the free feature override applies exclusively to the local environment. The later local override and tariff-visibility edits have not been deployed.

1. Deploy the pushed code and run pending migrations, including the Pro foundation, `2026_09_23_000300_add_private_pro_plan`, and the ad featured flag, before publishing the frontend.
2. Keep the rollout private and production charging disabled.
3. Create the private tester on the server with `business-pro:create-tester --allow-production --email=... --password=...`, using a new strong password supplied securely in the deployment session. The command refuses to repurpose an unrelated existing account. The weak local password and local fake business page are not deployed.
4. Configure a working Cardcom test API account and public HTTPS return/webhook URLs. Complete successful/failed checkout, duplicate callback, return-without-webhook, token renewal and cancellation tests.
5. For real payments obtain the merchant terminal and appropriate LowProfile/token/recurring permissions from Cardcom, configure the real API keys, receipts/documents and actual recurring terminal settings. `IsAutoRecurringPayment` does not create a recurring plan; enable it only when Cardcom instructs you to for that terminal.
6. An explicit `CARDCOM_ENV=production` plus `CARDCOM_PRODUCTION_ENABLED=true`, billing and renewal switches are required. Sandbox receipts/tokens cannot grant or renew production access. Moving a sandbox account to its first real checkout preserves test history but clears simulated paid periods and tokens. Moving a real contract between terminals or into sandbox is refused.
7. Public charging, commercial terms and future page packages are separate release decisions; paid feature controls remain visible to everyone, while the tariff blocks stay restricted during private rollout. This task leaves `BUSINESS_PRO_ROLLOUT=private`.
