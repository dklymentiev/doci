# MCP, API and CLI

← [Tour index](/tour/00-start-here)

## One operation, four ways

Every operation DOCI can perform is reachable from four surfaces. Pick
whichever fits the situation; they all hit the same code path under
`api/*.php`.

### 1. Browser UI

Buttons in the breadcrumb bar, context menu on text selection, sidebar
clicks. This is what you've been clicking through the tour.

### 2. REST

Plain HTTP. `X-API-Key` header for service-to-service, or `Remote-User`
header set by a reverse proxy for human users.

Create a document:

```bash
curl -X POST http://localhost:8080/api/documents.php \
  -H "X-API-Key: dev-test-key-not-for-production" \
  -H "Content-Type: application/json" \
  -d '{
    "path": "notes/from-rest.md",
    "content": "# From REST\n\nHello.",
    "title": "From REST",
    "tags": ["demo"]
  }'
```

Endpoints overview:

| Endpoint | Methods | Operation |
|---|---|---|
| `/api/documents.php` | GET/POST/PUT/DELETE | Document CRUD |
| `/api/inbox.php` | GET/POST | Inbox add/list/get/promote |
| `/api/thread.php` | GET/POST | Threads |
| `/api/thread-reply.php` | POST | Reply to thread |
| `/api/versions.php` | GET/POST | Version snapshots |
| `/api/register.php` | POST | Register an existing file |
| `/api/search.php` | GET | Search |
| `/api/ai-response.php` | POST | Generate AI response on a thread |
| `/api/validate-selection.php` | POST | Validate quote selection before thread |
| `/api/health.php` | GET | Liveness probe |

### 3. CLI (`deep`)

A POSIX shell wrapper around the REST API, scoped to inbox-heavy
workflows. Set `DOCI_URL` and `DOCI_API_KEY` env vars and:

```bash
./deep inbox "Quick note"           # capture
./deep inbox "With tags" -t a,b     # capture with tags
./deep list                          # list inbox
./deep show <guid>                   # show content
./deep promote <guid> docs/x.md     # promote inbox to doc
```

The script itself is ~50 lines (`./deep`); look at the source if you
want to wrap more operations.

### 4. MCP server

`mcp_server.py` -- FastMCP-based server that exposes 12+ tools.
Connect from Claude Code, Claude Desktop, or any MCP client by adding
it to your MCP config:

```json
{
  "mcpServers": {
    "doci": {
      "command": "python",
      "args": ["/path/to/doci/mcp_server.py"],
      "env": {
        "DOCI_API_URL": "http://localhost:8080/api",
        "DOCI_API_KEY": "dev-test-key-not-for-production"
      }
    }
  }
}
```

Tools available to your agent:

| Tool | What it does |
|---|---|
| `doci_create` | Create a document |
| `doci_get` | Get a document (by guid or path) |
| `doci_update` | Update content / title / tags |
| `doci_delete` | Soft-delete |
| `doci_inbox` | Capture to inbox |
| `doci_inbox_list` | List inbox |
| `doci_thread` | Start a thread |
| `doci_threads` | List threads for a document |
| `doci_reply` | Reply on a thread |
| `doci_versions` | List versions |
| `doci_search` | Search (semantic if Mesh available) |
| `doci_health` | Health check |

## Why four surfaces

The product invariant: **anything the agent can do, the human can do,
and vice versa.** No operation lives in only one of them. If you find
something that does, file a bug.

This means agents can hand off mid-task -- "I created a draft at X,
take it from here" -- and you take over in the UI without any
translation step. And vice versa: you start writing, the agent picks
up where you left off, no API mismatch.

→ Next: [Deployment](/tour/08-deployment)
