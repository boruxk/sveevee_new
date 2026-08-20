# Live Server Access

Use the local SSH alias:

```bash
ssh live-sveevee
```

The alias is configured in the local user SSH config, outside this repo:

```sshconfig
Host live-sveevee
    HostName v2202608395591497245.quicksrv.de
    Port 22
    User codex
    IdentityFile ~/.ssh/codex_live_sveevee_ed25519
    IdentitiesOnly yes
```

No password is stored in this repository. The private key stays in the local
user SSH directory.
