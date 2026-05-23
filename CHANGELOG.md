# Changelog

All notable changes to DOCI are documented here.
This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- **MCP server.** `mcp_server.py` (FastMCP) exposes 10+ tools for AI
  agents: `doci_create`, `doci_get`, `doci_update`, `doci_delete`,
  `doci_inbox`, `doci_inbox_list`, `doci_thread`, `doci_threads`,
  `doci_reply`, `doci_versions`, `doci_search`.
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
- Threads + versions UI tabs (planned) are not yet implemented; data
  layer is ready.

[Unreleased]: https://github.com/dklymentiev/doci/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/dklymentiev/doci/releases/tag/v0.1.0
