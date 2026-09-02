# DNS rollout

Publish these TXT records before enabling application SMTP delivery. A TTL of 600 is fine.

| Host | Type | Value |
| --- | --- | --- |
| `@` | `TXT` | `v=spf1 ip4:185.163.118.100 mx ~all` |
| `mail` | `TXT` | `v=spf1 ip4:185.163.118.100 -all` |
| `s1._domainkey` | `TXT` | `v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAwoUAqivQPYAU37ys5DWZ4XN5ms8KzKwhZCHX1HjW6ER26jvgaggSIOck1mlcJihGNmpax3EWT28LP2SrSfnmS40TI2Y6JB2xY9MVrTqZlQF7cO3X1YEfXoiP5YtVp/vbP8c2XJASgqBSG43qp+rG4MMj26L4pAnjr3LvfpOAVavwvBElBiSMjHRXLgnoVdzvTk5C3EjG3uQa1kCoLRZbpWh3fZsq+/JOIZddC/NmYvG9J6k2OGFPDmwQ+n45oGmXZDJ5idejCbA7Z9kxUj6cVYz2vFyBytdFPIF11nwk8SyPzvB3s3C8ugB/7YVGjGbolRL4LSPDGkXV9/ns6au6pQIDAQAB` |
| `_smtp._tls` | `TXT` | `v=TLSRPTv1; rua=mailto:tlsrpt@sveevee.co.il` |
| `_mta-sts` | `TXT` | `v=STSv1; id=20260902T0234Z` |

Keep the existing DMARC record during the test phase:

`v=DMARC1; p=quarantine; rua=mailto:dmarc@sveevee.co.il`

Do not change the MX record yet. After DKIM, SPF, TLS-RPT, and MTA-STS resolve publicly and external delivery tests pass, replace the current MX with `mail.sveevee.co.il` at priority 10. Keep Deomail operational for at least 48 hours after the cutover.
