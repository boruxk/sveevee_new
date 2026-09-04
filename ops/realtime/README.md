# Sveevee realtime deployment

Laravel Reverb serves account and admin notifications. Guest traffic, chat and presence do not depend on it. The existing notification heartbeat remains the fallback if Reverb is unavailable.

## Production environment

Generate three independent random values on the server. Do not commit the secret:

```dotenv
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=sveevee-production
REVERB_APP_KEY=<public-random-key>
REVERB_APP_SECRET=<private-random-secret>
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_ALLOWED_ORIGINS=sveevee.co.il,www.sveevee.co.il
```

Expose the same public key to the frontend build:

```dotenv
VITE_REVERB_APP_KEY=<public-random-key>
VITE_REVERB_HOST=sveevee.co.il
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

## Installation after the application push

1. Run `composer install --no-dev --optimize-autoloader` in `backend` and `npm ci` in `frontend`.
2. Set the environment values above, run `php artisan migrate --force`, then rebuild the frontend.
3. Install `supervisor/sveevee-reverb.conf` as `/etc/supervisor/conf.d/sveevee-reverb.conf`.
4. Include `nginx/reverb-locations.conf` in the existing HTTPS server block.
5. Run `sudo supervisorctl reread`, `sudo supervisorctl update`, and `sudo supervisorctl restart sveevee-reverb`.
6. Run `sudo nginx -t` before reloading Nginx. Keep TCP 8080 closed in UFW.
7. Restart the existing Sveevee queue workers so broadcasts and status e-mails use the new code.

## Smoke checks

- `sudo supervisorctl status sveevee-reverb` reports `RUNNING`.
- `ss -ltnp | grep 8080` shows only `127.0.0.1:8080`.
- A signed-in browser connects to `wss://sveevee.co.il/app/<key>` and can authorize only its own `private-users.<id>` channel.
- Approving a claim in a second browser updates the user's bell and navigation without a reload.
- With Reverb stopped, a focus event or the next heartbeat still retrieves the notification.
