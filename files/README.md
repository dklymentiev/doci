# README

*A self-hosted markdown workspace your AI agents can read, write, and discuss with you.*

Conversations with AI usually live in a chat tab or a model session — and then they're gone. The drafts, summaries, decisions, and research the agent produced go with them.

Agents are good at writing markdown. DOCI is a viewer for that markdown — a stripped-down Google Docs where the storage is a plain folder of files on disk. You ask your agent to put a document somewhere ("create `meetings/2026-05-24-standup.md`"), it does, and sends you back a link. You open the link, the document is there. The folder tree on the left lets you <!-- @thread:51a4a00a-5355-418c-bfe5-9d664ffa45cf -->browse, every document has a stable identifier<!-- @/thread:51a4a00a-5355-418c-bfe5-9d664ffa45cf --> so links keep working even when files get renamed or moved.

It works both ways. Click *Edit* on any page and the document opens in an inline editor; saves are normal file writes, so the next time the agent reads the file it sees your changes. Edits the agent makes through MCP or REST become commits the same way. The history strip above every page lists the recent commits with author and timestamp — you can see when each change happened, and whether it was you in the browser or the agent acting through the API.

Three things sit on top of that base, and they're what makes DOCI different from "a folder of files plus `cat`".

---

## On this page

1. **Threads on any passage** — talk to the AI about a specific sentence, right inside the document
2. **Canonical documents** — mark the ones that matter so new readers know where to start
3. **Markdown or full HTML** — embed dashboards, charts, anything the browser understands
4. **Get started** — three commands and a config snippet
5. **For agents** — same operations through REST, MCP, or a small CLI

---

## Threads on any passage

You're reading a document and want to discuss one paragraph. Select the text, right-click, *Start thread*. A new thread page opens — itself a plain markdown file on disk, anchored to the exact quoted passage. You write a comment, the AI drafts a reply, you reply again. The conversation becomes a document the next agent run can also read.

> "Agents generate a lot — drafts, summaries, decisions, reports, research notes."

[Open the thread on this passage →](/51a4a00a-5355-418c-bfe5-9d664ffa45cf)

Threads can be opened on threads — there's no depth limit. Starting a thread also auto-snapshots the source document into a frozen *version*, so the discussion stays pinned to the exact text it was written about even after the live document is edited. The version bar at the top of every page (`Original · DV-1 · DV-2`) walks the chain.

---

## Canonical documents

Some documents matter more than others — a brand-voice guide, an on-call runbook, a role description, the project's quickstart. Flip the **Canonical** switch in the bar above the page (right of the GUID) and the document is marked. In the sidebar canonical files render in bold with an underline, so a new person (or agent) on the project sees what to read first instead of guessing. The **Canonical only** toggle at the top of the sidebar collapses the tree to just those documents. The same file can be canonical in several domains at once — a brand-voice guide can matter for both *marketing* and *support*.

---

## Markdown or full HTML

A page can be plain markdown, or it can be a full HTML file with its own CSS and JavaScript — dashboards, charts, embedded widgets. Both render at their own URL and link to each other the same way. This page is markdown. The farm demo linked below mixes both.

[**Open the farm demo →**](/2d3fc36b-e31d-450a-b521-cd244902f414) — a small CSA-farm workspace with an operations dashboard, KPIs, an embedded chart, a quoted discussion, and a snapshotted version. Exercises every feature on this page in one connected workspace.

---

## Get started

### 1. Run DOCI locally

```bash
git clone <repository-url>
cd doci
docker compose -f docker-compose.dev.yml up -d
```

Postgres comes embedded, migrations apply on boot, the folder you cloned is indexed automatically. UI on <http://localhost:8080> — you're auto-signed-in as `dev`.

### 2. Connect an MCP agent

Point Claude Desktop, Claude Code, or any other MCP client at the bundled FastMCP server:

```json
{
  "mcpServers": {
    "doci": {
      "command": "python",
      "args": ["mcp_server.py"],
      "cwd": "/absolute/path/to/doci",
      "env": {
        "DOCI_API_URL": "http://localhost:8080/api",
        "DOCI_API_KEY": "dev-key"
      }
    }
  }
}
```

After restart, the agent sees `doci_create`, `doci_get`, `doci_search`, `doci_thread`, `doci_reply`, `doci_versions`, `doci_inbox`, … — **12 tools**. They are thin wrappers over the same REST API the web UI uses; whatever you can do, the agent can do, and vice versa.

### 3. Or talk to it over HTTP

```bash
export DOCI_API_KEY=dev-key
curl -H "X-API-Key: $DOCI_API_KEY" \
  http://localhost:8080/api/documents.php?path=docs/01-vision
```

Full contract: ten endpoints, documented in [`docs/05-api-reference`](/fe0900b5-c41f-4d69-9b13-9d5878597946).

---

## For agents

Every operation in the UI is also a REST endpoint and an MCP tool. The contract is the same; there is no parallel "agent API" that drifts from the human one. Auth is `X-API-Key` for service-to-service, or a `Remote-User` header from a reverse proxy for browser sessions.

| Surface | Entry point |
|---|---|
| HTTP | [`docs/05-api-reference`](/fe0900b5-c41f-4d69-9b13-9d5878597946) — 10 endpoints |
| MCP | [`mcp_server.py`](/fe0900b5-c41f-4d69-9b13-9d5878597946) — 12 tools |
| CLI | [`./deep`](/fe0900b5-c41f-4d69-9b13-9d5878597946) — inbox-heavy workflows |

---

## Read next

- [**Vision**](/e89792b1-bf40-4c47-b96b-6caa4d2fec29) — why DOCI exists and what it is not
- [**Spec**](/a5eddd4e-88e5-4405-9fbe-8e4cd75818d7) — the document model and every operation
- [**Architecture**](/e13b7526-9fba-4799-a3bb-179217865304) — layers and design decisions
- [**Operations**](/ce4f113e-d348-4414-8d51-f1ca18312f80) — deploy, monitor, recover
