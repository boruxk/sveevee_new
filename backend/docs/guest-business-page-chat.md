# Guest business-page chat

Guest chat is available for owned business pages whose owner is not banned. It uses the normal business inbox and does not create a user account. The browser keeps its opaque token in session storage; only its SHA-256 hash is stored in `page_conversations`. Closing the browser session does not delete the business's history.

## API

All routes use the `/api/v1` prefix. Existing reCAPTCHA checks remain enabled for writes.

| Method and path | Authentication | Response `data` |
| --- | --- | --- |
| `POST /pages/{page}/guest-chat` | Public; `{body, locale?}` | `{token, conversation}` |
| `GET /pages/{page}/guest-chat` | `X-Guest-Page-Chat-Token` header | Conversation with messages |
| `POST /pages/{page}/guest-chat/messages` | Same header; `{body}` | Conversation with messages |
| `POST /pages/{page}/guest-chat/claim` | Account bearer token and guest header | Normal account conversation |

Guest identities have `is_guest: true`, a null user ID, and no public profile path. The guest can send one initial message, then must wait for a business reply, like a registered visitor. New sessions are limited to 3 per minute and 10 per hour per IP. Further messages use the configured chat rate limit. Message length and content moderation match normal chats.

Registration through the chat's call to action invokes `claim` after authentication, including Google registration, before profile completion. Claiming transfers guest message ownership to the account. If that account already has a conversation with the page, both histories are merged by moving rows without changing their timestamps or read status. A private claim receipt permits safe retries by the same account; another account cannot claim it. The guest token immediately stops working for anonymous reads and writes.

The existing email notification observer and unread-email job handle private `ChatMessage` records only. Page chats continue using their existing inbox and polling behavior; no guest email address or recipient is invented.

## Deployment

After the user has pushed and explicitly authorized the live update, back up the database and deploy the backend migration before exposing the new frontend:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
```

Migration `2026_09_10_000500_enable_guest_business_page_chats` makes conversation visitors and message senders nullable and adds hashed guest tokens and private claim receipts. Existing conversations and messages are preserved. Standard unsigned foreign keys retain cascading deletion of chat data with its page/account; receipt references use `ON DELETE SET NULL`. No scheduler, worker, or import timer change is required.

Rollback refuses to discard guest conversations: while null participants remain, restore/migrate that data explicitly before reverting this schema. A code rollback should preserve the upgraded schema and must account for guest identities in inbox payloads.

## Verification

```sh
php artisan test tests/Feature/GuestPageChatApiTest.php tests/Feature/GuestPageChatMigrationTest.php
```

The API tests cover token privacy and page binding, owner replies/read counts, incomplete-profile registration and history adoption, merge/retry behavior, moderation, throttling, ownership changes, bans, and deletion. The migration test uses an isolated SQLite database and verifies pre-existing account history and foreign keys across the upgrade.
