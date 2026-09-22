# Business Pro / עסק פרו

Business Pro is an account subscription covering **one selected, currently owned business page**. The initial monthly offer is **49 ILS** (`4900` agorot). The admin's Business Pro tab changes the price for **new contracts**. Existing subscriptions keep their agreed price; the browser submits the displayed price and the backend requires consent again if it changed.

There is no public launch in this change. Existing free features stay free. A future multi-page package can extend `plan_key` / `included_pages`; no extra-page pricing or extra-page entitlement exists yet.

## Local preview

- Login: `pro@sveevee.local`, local password `password`.
- Open the Business Pro section in your profile (`/profile#business-pro`). `/business-pro` redirects there for existing links.
- The local account has a claimed `Business Pro Test` page. It has **no paid entitlement**.
- The profile shows a compact offer with a purchase button. Selecting a business page, monthly consent and payment take place in its dialog; paid accounts can manage their subscription there. The ordinary customer view appears only after public rollout is enabled.
- Admin: Business Pro tab contains offer price, subscriptions, payments and feature controls.
- The four interface languages are Hebrew, English, Russian and French.

Only admins and the dedicated DB-marked tester receive the private preview flag. Other users and guests see no Pro navigation or feature descriptions; direct API access is denied. The tester flag and payment status are not mass-assignable from normal profile/page requests.

## Cardcom verification on 2026-09-22

Primary references:

- [Official shared test terminal](https://support.cardcom.solutions/hc/he/articles/360002688814)
- [JSON v11 OpenAPI](https://secure.cardcom.solutions/swagger/v11/swagger.json)
- [LowProfile and payment confirmation](https://cardcomapi.zendesk.com/hc/he/articles/28448202810514)
- [Token charging](https://cardcomapi.zendesk.com/hc/he/articles/28452352778770)
- [API key administration](https://support.cardcom.solutions/hc/he/articles/38687626381970)

Cardcom publishes terminal `1000` and states that its test transactions do not actually debit the card. The v11 documented API base is `https://secure.cardcom.solutions/api/v11`. A different hostname alone does not make a transaction a test; this client enforces terminal/environment consistency and requires an additional explicit production flag.

**External test currently blocked:** a direct read-only v11 query using the credentials currently published by Cardcom returned **HTTP 401 / ResponseCode 603**, meaning incorrect username/password. The same check against `test.cardcom.solutions` returned the same error. No hosted payment page was obtained, no card was submitted and no payment was performed. Therefore a complete external checkout/webhook/token renewal flow has **not** been verified. The local payment switch remains off until Cardcom supplies or restores a working test API account. The failed setup probes are retained as local failed payment records.

The published shared credentials are not committed. Local `.env` contains the current public test credentials for replacement when they work again. Do not replace them with real merchant credentials for local testing.

## Configuration

```dotenv
BUSINESS_PRO_ROLLOUT=private
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

Only set `BUSINESS_PRO_BILLING_ENABLED=true` after test credentials are working. To test automatic renewal processing explicitly enable `BUSINESS_PRO_RENEWALS_ENABLED`; it defaults off. Neither switch is changed by an admin editing the price.

The client has no arbitrary API base URL and never forwards PAN/CVV data. Card entry happens on Cardcom's hosted page. Tokens are encrypted at rest and excluded from all public/admin API responses. API passwords are configured for later provider operations; the implemented v11 creation, receipt and token endpoints authenticate with terminal and API name as documented.

## Payment lifecycle

1. Authenticated eligible account selects its claimed business page and consents to the displayed monthly amount.
2. The backend validates ownership, the server price and callback configuration. It serializes checkout creation on the account and persists a payment snapshot.
3. `LowProfile/Create` uses `ChargeAndCreateToken`; the returned Cardcom HTTPS URL opens the hosted page.
4. The webhook or authenticated return verification calls `LowProfile/GetLpResult`. The return URL and webhook payload themselves never grant access.
5. Access requires a successful charge, matching terminal/order/amount/currency/operation and non-refund receipt. A paid period is recorded once. Cardcom's actual field spellings include `TranzactionInfo` and `TranzactionId`.
6. A valid token is encrypted for renewal. If token creation fails after a successful first charge, the paid month is honored and automatic renewal is disabled.
7. At renewal, the server uses the contract's saved amount and one immutable `ExternalUniqTranId` for that billing period. Unknown network outcomes are reconciled through `GetTransactionByExternalUniqTran`; they never create another charge attempt with a new ID. A declined renewal is not retried automatically.
8. Cancellation stops future renewals and retains access through the confirmed paid period. Expiry, losing the selected page, deletion, banning or environment mismatch removes access. A late payment after ownership changes is recorded for admin review without enabling another owner's page or scheduling further charges.

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
        'labels' => ['he' => '...', 'en' => '...', 'ru' => '...', 'fr' => '...'],
        'descriptions' => ['he' => '...', 'en' => '...', 'ru' => '...', 'fr' => '...'],
        'sort_order' => 10,
    ],
],
```

Run `php artisan business-pro:sync-features`. This creates a **disabled draft** DB row and preserves existing admin settings. The admin can enable an implemented draft for private testing; the global rollout remains private. A database row without implemented code cannot unlock a feature. No fictional features are seeded in the initial registry.

For page-scoped feature endpoints use `auth:sanctum` plus `business-pro.feature:your_feature` on a bound `{page}` route, or call `BusinessProEntitlementService::assertFeature($user, $page, 'your_feature')` inside the service. Apply the check to reads, writes and background jobs as appropriate; CSS disabling alone is not protection.

Render private controls with `BusinessProFeature.vue` and the authoritative page-specific feature snapshot from `GET /api/v1/business-pro/pages/{page}`. Locked slots are not mounted. For public-facing output of a future feature, explicitly gate the output too; don't add draft data to general page payloads. Use this same check whenever ownership or paid-through dates can have changed.

## Deployment later, after push and explicit approval

No live deployment or live test user was created for this task.

1. Deploy the pushed code and migrate `2026_09_22_000200_create_business_pro_foundation` before publishing the frontend.
2. Keep the rollout private and production charging disabled.
3. Create the private tester on the server with `business-pro:create-tester --allow-production --email=... --password=...`, using a new strong password supplied securely in the deployment session. The command refuses to repurpose an unrelated existing account. The weak local password and local fake business page are not deployed.
4. Configure a working Cardcom test API account and public HTTPS return/webhook URLs. Complete successful/failed checkout, duplicate callback, return-without-webhook, token renewal and cancellation tests.
5. For real payments obtain the merchant terminal and appropriate LowProfile/token/recurring permissions from Cardcom, configure the real API keys, receipts/documents and actual recurring terminal settings. `IsAutoRecurringPayment` does not create a recurring plan; enable it only when Cardcom instructs you to for that terminal.
6. An explicit `CARDCOM_ENV=production` plus `CARDCOM_PRODUCTION_ENABLED=true`, billing and renewal switches are required. Sandbox receipts/tokens cannot grant or renew production access. Moving a sandbox account to its first real checkout preserves test history but clears simulated paid periods and tokens. Moving a real contract between terminals or into sandbox is refused.
7. Public rollout, commercial terms and future pages packages are separate release decisions. This task leaves `BUSINESS_PRO_ROLLOUT=private`.
