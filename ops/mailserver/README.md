# Sveevee mail server

Production configuration for the self-hosted mail stack on Debian 13.

- Postfix receives mail and provides authenticated submission on ports 465 and 587.
- Dovecot stores the `info@sveevee.co.il` Maildir and exposes IMAPS on port 993.
- Rspamd, Redis, and ClamAV provide spam and malware filtering and DKIM signing.
- Roundcube is served at `https://webmail.sveevee.co.il`.
- Application delivery-status notifications use tokenized `bounce+...@mail.sveevee.co.il` addresses.
- Private keys, mailbox password hashes, and Roundcube database credentials stay on the server and are not committed.

Mailbox backups are intentionally not configured. Lost mailbox data cannot be restored.
