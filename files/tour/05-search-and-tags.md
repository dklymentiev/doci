# Search and tags

← [Tour index](/06a1f168-84b3-4461-b7a3-3bea4164c77a)

## Built-in: title, path, tags

The search bar in the top breadcrumbs sends you to a results page that
matches against titles, paths, and tags. It is backed by Postgres GIN
indexes on `tags` and direct LIKE on `title`/`path`.

**Try it:**

1. Type `tour` into the search bar at the top of any page.
2. You'll land on a results page with every document tagged or pathed
   with `tour`.
3. Open one with a hover-preview by clicking the result card. (A
   slide-out panel previews the document without leaving the search
   page.)

## Folder-scoped search

When you're inside a folder (any path that resolves to a directory),
the search form scopes itself to that folder. Searching from
`/tour/` only returns hits under `tour/*`. The form carries a hidden
`folder=tour` field.

## Tags

Tags live in the `tags` text array on the `documents` row, GIN-indexed
for fast filtering. Set them at create or update:

```bash
curl -X PUT 'http://localhost:8080/api/documents.php' \
  -H "X-API-Key: dev-test-key-not-for-production" \
  -H 'Content-Type: application/json' \
  -d '{"guid":"...", "tags":["onboarding","demo","reference"]}'
```

Read them back in the document metadata bar, or query directly:

```sql
SELECT path, title FROM documents
WHERE 'onboarding' = ANY(tags) AND deleted_at IS NULL;
```

## Optional: semantic search via mesh-memory

If you also run [mesh-memory](https://github.com/dklymentiev/mesh-memory)
and set `MESH_API_URL`, DOCI will:

- On every document create/update, asynchronously upsert the content
  into Mesh with tag `source:doci` (see `lib/mesh.php` for the
  implementation).
- Expose a `doci_search` MCP tool that proxies queries to Mesh's
  `/search` endpoint -- semantic matching by meaning, not text.

When Mesh is unreachable the integration silently no-ops; lexical
search keeps working.

## When you'd use which

- **Title/path/tag (built-in):** "show me everything tagged `incident`"
  or "find that doc about caching."
- **Semantic (Mesh):** "find documents about backup strategies even if
  they use words like `snapshot`, `restore-point`, `dump`."

Both are queryable from agents via MCP, so the agent's search
behavior matches yours.

→ Next: [Navigation tour](/a34e6b54-38fb-4c55-b75d-da09c148a561)
