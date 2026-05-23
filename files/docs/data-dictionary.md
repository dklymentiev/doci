# DOCI -- Data Dictionary

**Database:** `doci`
**App user:** `doci_app`
**Initial migration:** `migrations/001_initial_schema.sql`

## Tables

### `documents`

| Column | Type | Notes |
|---|---|---|
| `guid` | `UUID PRIMARY KEY DEFAULT gen_random_uuid()` | Stable across renames |
| `path` | `TEXT` | Relative to `files/`, ends in `.md` |
| `parent_guid` | `UUID` | Thread -> parent document |
| `original_guid` | `UUID` | Version -> original document |
| `doc_type` | `TEXT DEFAULT 'document'` | `document` \| `inbox` \| `thread` \| `version` |
| `title` | `TEXT` | |
| `summary` | `TEXT` | One-line summary |
| `tags` | `TEXT[]` | GIN-indexed |
| `quote` | `TEXT` | (Threads) selected passage on parent |
| `created_by` | `TEXT` | Identity at create time |
| `created_at` | `TIMESTAMPTZ DEFAULT NOW()` | |
| `updated_at` | `TIMESTAMPTZ` | NULL until first update |
| `updated_by` | `TEXT` | Identity at last update |
| `access` | `TEXT` | Reserved for per-doc ACL (not wired in v0.1) |
| `deleted_at` | `TIMESTAMPTZ` | Soft-delete tombstone |

## Indexes

```sql
CREATE UNIQUE INDEX documents_path_unique ON documents (path);
CREATE INDEX idx_documents_parent   ON documents (parent_guid);
CREATE INDEX idx_documents_original ON documents (original_guid);
CREATE INDEX idx_documents_path     ON documents (path);
CREATE INDEX idx_documents_tags     ON documents USING gin (tags);
CREATE INDEX idx_documents_type     ON documents (doc_type);
```

## Functions

### `get_document_hierarchy(doc_guid UUID)`

Recursive CTE walking the parent chain from `doc_guid` to root.
Returns rows ordered root-first, each carrying `guid`, `path`, `title`,
`depth`. Used by the UI breadcrumb and by `documents.php::getHierarchy`.

## Filesystem mapping

```
files/
|-- inbox/                                       # doc_type = 'inbox'
|   `-- 20260124-143022.md
|-- .data/
|   |-- versions/<original-guid>/<version-guid>/index.md
|   |                                            # doc_type = 'version'
|   `-- threads/<parent-guid>/<thread-guid>/index.md
|                                                # doc_type = 'thread'
`-- <any folders...>                             # doc_type = 'document'
```

The path on disk encodes `doc_type` for threads/versions/inbox; the
metadata column is redundant (and authoritative).

## Soft delete

`deleted_at IS NOT NULL` -> the row is gone from every default query.
The file on disk stays; a `Delete <path>` git commit is recorded so
the absence is auditable. To restore: `UPDATE documents SET deleted_at
= NULL WHERE guid = '...'`.

## Connection

```bash
# Dev (docker-compose.dev.yml exposes Postgres on 5433)
psql -h 127.0.0.1 -p 5433 -U doci_app -d doci

# List undeleted documents
SELECT guid, doc_type, path, title
FROM documents
WHERE deleted_at IS NULL
ORDER BY updated_at DESC NULLS LAST;
```

## Rebuilding the index

The `documents` table is derived data. To rebuild from `files/`:

```bash
docker compose exec doci php scripts/index-documents.php
```

This walks the tree, inserts a row for every `.md` file not already
indexed (matched by path), and leaves existing rows alone. Lost
metadata (tags, summaries) is not recoverable from files alone -- they
were authored through the API, not embedded in the markdown.
