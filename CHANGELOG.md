# Changelog

All notable changes to DOCI are documented here.
This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Canonical documents** — curated registry, many-to-many onto domain
  strings (`marketing`, `support`, `incidents`, …). New endpoint
  `GET/POST/DELETE /api/key-document.php`, a **Canonical** switch in
  the page header (right of the GUID), and a **Canonical only** toggle
  at the top of the sidebar that collapses the tree to just those
  documents. Canonical files render bold + underlined; folders that
  contain a canonical descendant render bold (planner #1937).
- `migrations/002_key_documents.sql` — `key_documents` table + indexes.
- `migrations/003_hierarchy_full_chain.sql` — rewrite
  `get_document_hierarchy()` to walk both thread→version and
  version→original links so an AI prompt sees the full ancestry.
- Unit tests: `tests/Unit/MarkdownTest.php` (extract_title, HTML
  detection, thread-quote matching, footnote rewriting),
  `tests/Unit/MeshTest.php` (GUID derivation, skip-folder config),
  `tests/Unit/PathSafetyTest.php` (validate_path_within against a real
  temp directory).
- `docs/04-roadmap.md` — phased delivery with exit gates per phase.
- `docs/05-api-reference.md` — full HTTP API + CLI + MCP tool contract.
- `docs/06-testing-strategy.md` — test pyramid + mandatory live benchmark gate
  per Software Product Template v1.1.
- `docs/07-operations.md` — deploy, monitor, backup, incident runbook.
- `docs/08-changelog.md` — stub pointing at this file.
- `docs/09-doc-pipeline.md` — three documentation layers and how they stay current.
- `docs/quickstart.md` and `docs/monitoring.md`.
- `examples/` — runnable curl + Python REST clients, Claude Desktop MCP config.
- `schemas/` — JSON Schemas for `document`, `inbox`, `thread`, `version`.
- `RELEASING.md` — release checklist, semver policy, rollback plan.
- `Makefile` — operator shortcuts (`make help`, `lint`, `test`, `api-test`,
  `up`, `down`, `deploy`, `psql`, etc.).
- `.github/workflows/test.yml` — CI matrix (PHP 8.2/8.3) running
  PHPUnit + PHPStan + `php -l` lint.

### Changed
- **Root document is `files/README.md`, not `files/index.md`.** Router
  in `index.php` falls back to `README.md` when `index.md` is absent
  (search "Root-document fallback"). `$requestPath` stays as `'index'`
  internally, so nav / breadcrumbs / version-bar logic continues to
  treat it as the root. The editorial landing (TOC, three feature
  sections, Get started with MCP config, farm CTA, Read next) lives in
  README.md; the old `files/index.md` was deleted.
- **Sidebar tree hides `index.md` / `index.html`** at every level
  (`render_file_tree` in `lib/render.php`). They are the implicit
  landing of their level — listing them as a separate child duplicated
  the parent-click target.
- **Landing drops the appended folder-cards grid.** The root URL `/`
  now renders the curated landing only; sub-directory index pages
  still get folder/file cards (unchanged).
- **AI gateway** — `ai.php` now talks to any OpenAI-compatible
  `/chat/completions` endpoint via `AI_GATEWAY_URL`, replacing the
  previous internal gateway protocol.
- **Internal links** — saves rewrite `[text](relative/path)` references
  to their `documents.guid` form on write, not only at render time, so
  the markdown source stays stable when files are renamed.
- **Container start** — the entrypoint indexes anything in `files/`
  that has no DB row yet, so seeded markdown gets GUIDs on first boot
  without a manual `scripts/index-documents.php` call.
- `files/` wiped for landing rebuild (Phase 4 of the roadmap). Old demo content
  preserved in git history at commit `9cfa34d`.

### Removed
- `doci.local` as a hardcoded fallback for `DOCI_DOMAIN`. The variable
  is now required at boot; the container fails fast if it is missing.

## [0.1.0] - 2026-05-22

First open-source release. Code extracted from internal use at klymentiev.com.

### Features

- **Documents.** Hierarchical markdown documents with REST CRUD, GUID-stable
  identifiers, human-readable paths, soft delete, tags, parent/child links.
- **Inbox.** Quick capture of free-form notes; later promote to documents
  at a chosen path.
- **Threads.** Open discussions on a document or a selected quote inside
  it; threads are themselves first-class documents (`doc_type = 'thread'`).
- **Versions.** Snapshot a document into a version document
  (`doc_type = 'version'`) before edits; full chain queryable.
- **Git-backed storage.** Every create/update/delete is committed in the
  `files/` working tree; commit log doubles as audit trail.
- **AI thread replies.** Optional `ai.php` integration generates draft
  replies against an external AI gateway.
- **HTML documents.** Documents whose content is a full HTML page
  (starting with `<!DOCTYPE html>` or `<html>`) render in a sandboxed
  `<iframe>` (sandbox omits `allow-same-origin`).
- **Threads + versions UI.** Version bar at the top of every document
  (chips `Original | DV-1 | …`); right-click on a selection opens
  "Start Thread" which atomically creates a version + a thread
  anchored to the quoted passage; thread pages render the reply log
  with an inline reply textarea and optional AI reply checkbox.
- **MCP server.** `mcp_server.py` (FastMCP) exposes 12 tools for AI
  agents: `doci_create`, `doci_get`, `doci_update`, `doci_delete`,
  `doci_search`, `doci_inbox`, `doci_inbox_list`, `doci_thread`,
  `doci_threads`, `doci_reply`, `doci_versions`, `doci_health`.
- **Auth.** API key (`X-API-Key`, SHA-256 hashed at rest) and/or external
  auth via reverse proxy passing `Remote-User`. CSRF token for mutating
  HTTP methods.
- **Optional Mesh integration.** When `MESH_API_URL` is reachable,
  documents are indexed for semantic search via the companion project
  `mesh-memory` (https://github.com/dklymentiev/mesh-memory).

### Known limitations

- No multi-tenant isolation. Single org / single git repo per instance.
- No built-in user management. Auth is delegated to a reverse proxy or
  to API key holders.
- CSRF gate in `config.php::require_csrf_token` early-returns; re-enabled
  in Phase 5 of the roadmap.
- No built-in search endpoint; the architecture doc previously listed a
  `search.php` route that was never shipped. Path/title/tag lookup works
  through `GET /api/documents.php?path=…`; semantic search requires the
  optional Mesh integration.

[Unreleased]: https://github.com/dklymentiev/doci/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/dklymentiev/doci/releases/tag/v0.1.0
