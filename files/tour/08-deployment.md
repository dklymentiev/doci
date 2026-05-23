# Deployment

← [Tour index](/06a1f168-84b3-4461-b7a3-3bea4164c77a)

## Mode 1 -- local dev

What you're running right now.

```bash
docker compose -f docker-compose.dev.yml up -d
# UI:  http://localhost:8080
# DB:  127.0.0.1:5433 (doci_app / doci_dev_pass)
```

- `DOCI_DEBUG=true` is set, so any request without auth headers is
  treated as user `dev`.
- Embedded Postgres 16 in a sibling container with auto-applied
  migrations from `migrations/`.
- Source files live on the host; rebuild the image with
  `docker compose ... up -d --build` to pick up code changes.

Good for: trying DOCI, scripting demos, throwaway personal use.

## Mode 2 -- VPN-private (recommended for agents)

DOCI lives on a server. The server is reachable only via VPN
(WireGuard / Tailscale / OpenVPN). No public domain, no Let's
Encrypt, no auth provider needed.

```bash
# On the server
DOCI_DB_PASS='<strong>' \
DOCI_API_KEY_HASH=$(echo -n 'your-secret-key' | sha256sum | cut -d' ' -f1) \
docker compose up -d
```

On any client device that's on the VPN, add a line to `/etc/hosts`:

```
10.0.0.42   docs.local
```

Open `http://docs.local`. The agent gets the API key. You get the
browser. The VPN gives you both transport security and access control.

Why this is the best fit for the "agent ships you reports" loop:

- Zero attack surface beyond the VPN.
- No third-party in the path; everything's on your hardware.
- API key is a single secret you control and can rotate in one place
  (`DOCI_API_KEY_HASH`).
- TLS is optional -- inside the VPN HTTP is fine, but you can add
  Caddy on `:443` if you want LE certs anyway.

## Mode 3 -- public with auth

Externally reachable on a real domain, behind a reverse proxy with
SSO.

```
internet -> Caddy/Traefik -> Authentik/Authelia (ForwardAuth) -> DOCI
                                                                  |
                                                                  v
                                                               Postgres
```

DOCI reads `Remote-User` from the proxied request and treats that as
the authenticated identity. Mutations are attributed to that user in
git commits and the documents table.

```bash
DOCI_DEBUG=false \
DOCI_DOMAIN=docs.yourcompany.com \
DOCI_DB_PASS='<strong>' \
docker compose up -d
```

Wire your proxy to send `Remote-User`. Done.

## Hardening checklist

For anything past "running on my laptop":

- [ ] `DOCI_DEBUG=false` -- removes the dev auto-login bypass.
- [ ] `DOCI_API_KEY_HASH` -- generate the key, store the sha256 hash,
      keep the plaintext only in your password manager.
- [ ] Postgres password rotated from `doci_dev_pass`.
- [ ] Backups of `files/` (it's a git repo -- mirror it somewhere).
- [ ] Backups of Postgres (`pg_dump doci`).
- [ ] If exposed publicly: TLS in the reverse proxy, auth provider in
      front, `Remote-User` header injection.

## Backup model

Because files in `files/` ARE a git working tree, the cheapest backup
strategy is to push the repo to a private remote (Gitea, GitLab,
GitHub private). Run `git push --all` from the working tree on a cron.

Postgres is metadata; rebuild from scratch with
`scripts/index-documents.php` if needed, but you'll lose tags /
summaries / explicit titles. So back it up too -- `pg_dump doci` to
a daily file.

→ Next: [Versioning and backup](/f4693b25-1a07-45b7-946b-817e1fef689e)
