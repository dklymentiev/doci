# Instructions for Claude

Project: **DOCI** — Documented Agent Control System.

## What it is

PHP 8.2 web app + PostgreSQL metadata + git-backed document storage. Provides:
- Hierarchical markdown documents (CRUD via REST API)
- Inbox quick-capture
- Threaded discussions on documents
- Version history (git snapshots in `files/.data/versions/`)
- AI-powered thread replies (`ai.php`)
- MCP server (`mcp_server.py`) for agent integration

## Key files

- `index.php` — web UI + router
- `config.php` — config, auth, DB connection
- `documents.php` — document functions (register, get, hierarchy)
- `api/` — REST endpoints (documents, inbox, thread, versions, register)
- `lib/git.php`, `lib/validation.php`, `lib/response.php`
- `mcp_server.py` — MCP server (FastMCP, Python)
- `migrations/001_initial_schema.sql` — Postgres schema
- `files/` — runtime document storage (gitignored; user content lives here)

## Local dev

```bash
docker compose -f docker-compose.dev.yml up -d
# UI: http://localhost:8080
# DB: psql -h 127.0.0.1 -p 5433 -U doci_app -d doci
./tests/api-test.sh   # integration tests
```

## Conventions

- Documents identified by UUID (`guid`) — stable. Paths are human-readable but renameable.
- Every mutation goes through git. The Postgres `documents` table is metadata; `files/` is the source of truth.
- API auth: `X-API-Key` header (hash compared against `DOCI_API_KEY_HASH`), or external auth via reverse proxy passing `Remote-User`.
- CSRF token required for POST/PUT/DELETE.
