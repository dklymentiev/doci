# DOCI

> Self-hostable git-backed document workspace for AI agents:
> markdown documents, threaded discussions, version history, and an MCP
> server that exposes every operation as a tool.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP 8.2+](https://img.shields.io/badge/php-8.2%2B-blue.svg)](https://www.php.net/)
[![PostgreSQL 14+](https://img.shields.io/badge/postgres-14%2B-blue.svg)](https://www.postgresql.org/)

**Status:** v0.1.0 -- first open-source release. See [CHANGELOG.md](CHANGELOG.md).

Documents live as plain markdown files in `files/` (committed to git on every
change) with PostgreSQL holding the metadata (path, title, tags, version
chain, soft-delete tombstones). The HTTP API, the `mcp_server.py` (FastMCP),
and the `deep` CLI all sit on top of the same repository layer.

## Quick Start

```bash
# Clone and start (requires Docker)
git clone <repository-url>
cd doci
docker compose -f docker-compose.dev.yml up -d

# Wait for PostgreSQL to initialize, then open:
# http://localhost:8080

# Run API tests
./tests/api-test.sh
```

The dev environment includes an embedded PostgreSQL database. The schema is applied automatically from `migrations/001_initial_schema.sql`.

For production deployment with Traefik and external PostgreSQL, see `docker-compose.yml`.

## Architecture

```
doci/
├── index.php              # Web UI - document viewer
├── config.php             # Configuration, auth, DB
├── documents.php          # Document functions (register, get, hierarchy)
├── api/                   # REST API (single source of truth)
│   ├── documents.php      # CRUD for documents
│   ├── inbox.php          # Quick capture
│   ├── thread.php         # Discussion threads
│   ├── thread-reply.php   # Thread replies
│   ├── versions.php       # Document versioning
│   └── register.php       # Bulk registration
├── index-documents.php    # Mass indexing script
├── files/                 # Document storage
│   ├── inbox/             # Quick notes
│   ├── .data/
│   │   ├── threads/       # Discussion threads
│   │   └── versions/      # Document snapshots
│   └── {folders}/         # Organized documents
└── lib/
    ├── git.php            # Git integration
    ├── validation.php     # Input validation
    └── response.php       # JSON responses
```

## Document Types

| Type | Description | Key Fields |
|------|-------------|------------|
| `document` | Regular document | path, title, parent_guid |
| `folder` | Directory | path, title |
| `inbox` | Quick note | path (inbox/...) |
| `thread` | Discussion | parent_guid, quote |
| `version` | Snapshot | original_guid |

## API Reference

All API endpoints require authentication:
- **Traefik/Authum:** `Remote-User` header
- **API Key:** `X-API-Key` header

CSRF token required for POST/PUT/DELETE (header `X-CSRF-Token` or body `csrf_token`).

### Documents API

**Endpoint:** `/api/documents.php`

#### Create Document
```http
POST /api/documents.php
Content-Type: application/json

{
  "path": "docs/my-document",
  "content": "# My Document\n\nContent here...",
  "title": "My Document",
  "summary": "Optional summary",
  "tags": ["tag1", "tag2"]
}
```

Response:
```json
{
  "success": true,
  "guid": "a1b2c3d4-...",
  "path": "docs/my-document.md",
  "title": "My Document",
  "url": "/a1b2c3d4-..."
}
```

#### Get Document
```http
GET /api/documents.php?guid={guid}
GET /api/documents.php?path={path}
GET /api/documents.php?guid={guid}&content=1  # Include file content
```

#### Update Document
```http
PUT /api/documents.php
Content-Type: application/json

{
  "guid": "a1b2c3d4-...",
  "content": "Updated content",
  "title": "New Title",
  "summary": "New summary",
  "tags": ["new", "tags"]
}
```

#### Delete Document (soft delete)
```http
DELETE /api/documents.php?guid={guid}
```

### Inbox API

**Endpoint:** `/api/inbox.php`

#### Add to Inbox
```http
POST /api/inbox.php?action=add
Content-Type: application/x-www-form-urlencoded

content=Quick thought&tags=idea,api
```

#### List Inbox
```http
GET /api/inbox.php?action=list&limit=20
```

#### Get Inbox Item
```http
GET /api/inbox.php?action=get&guid={guid}
```

#### Promote to Document
```http
POST /api/inbox.php?action=promote

guid={guid}&target=docs/promoted-note.md
```

### Threads API

**Endpoint:** `/api/thread.php`

#### Create Thread
```http
POST /api/thread.php
Content-Type: application/json

{
  "parent_guid": "document-guid",
  "title": "Discussion title",
  "quote": "Selected text from document",
  "message": "Initial message"
}
```

### Thread Reply API

**Endpoint:** `/api/thread-reply.php`

#### Add Reply
```http
POST /api/thread-reply.php
Content-Type: application/json

{
  "thread_guid": "thread-guid",
  "message": "Reply content"
}
```

### Versions API

**Endpoint:** `/api/versions.php`

#### Create Version (snapshot)
```http
POST /api/versions.php
Content-Type: application/json

{
  "document_guid": "original-document-guid"
}
```

#### List Versions
```http
GET /api/versions.php?document_guid={guid}
```

### Register API

**Endpoint:** `/api/register.php`

#### Register Existing File
```http
POST /api/register.php
Content-Type: application/json

{
  "path": "existing/file.md",
  "title": "Optional title override"
}
```

## CLI

```bash
# Inbox operations
./deep inbox "Quick thought"
./deep inbox "With tags" -t idea,api
./deep list
./deep show <guid>
./deep promote <guid> docs/target.md

# Mass indexing
php index-documents.php
# Or via web:
curl "http://localhost:8080/index-documents.php?run=1"
```

## Routing

Documents accessible by:
- **GUID:** `/a1b2c3d4-5678-...` - stable, recommended
- **Path:** `/docs/my-document` - human-readable

Router logic:
1. Check if path is valid GUID -> lookup in DB
2. Check if `files/{path}.md` exists -> serve markdown
3. Check if `files/{path}.html` exists -> serve HTML
4. Check if `files/{path}/` is directory -> show folder cards
5. Otherwise -> 404

## Git Integration

All document changes are automatically committed to git:
- Create: `git add && git commit -m "Create {path}"`
- Update: `git commit -m "Update {path}"`
- Files tracked in `files/` directory

## Database

See [docs/data-dictionary.md](docs/data-dictionary.md) for schema details. Migration files are in `migrations/`.

```bash
# Connect (dev environment)
psql -h 127.0.0.1 -p 5433 -U doci_app -d doci

# Check documents
SELECT guid, path, doc_type, title FROM documents WHERE deleted_at IS NULL;
```

## Documentation

- [docs/01-vision.md](docs/01-vision.md) -- problem, audience, value
- [docs/02-spec.md](docs/02-spec.md) -- functional spec
- [docs/03-architecture.md](docs/03-architecture.md) -- components and decisions
- [docs/data-dictionary.md](docs/data-dictionary.md) -- database schema

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: [SECURITY.md](SECURITY.md).

## License

MIT -- see [LICENSE](LICENSE).

---

Created by [Dmytro Klymentiev](https://klymentiev.com)
