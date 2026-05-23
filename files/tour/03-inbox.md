# Inbox: quick capture

← [Tour index](/tour/00-start-here)

## The idea

You have a half-thought. You don't yet know where it belongs in your
folder structure. You don't want to interrupt your flow to invent a
path and a title for it. The inbox is the parking lot.

You dump text in. Later you decide:

- delete it (it was nothing)
- promote it to a real document at a chosen path (it grew up)
- leave it parked (you're still thinking)

## Try it: from the CLI

The shipped `deep` script is a thin shell wrapper around the inbox API.

```bash
./deep inbox "Thought about caching: GUID-keyed cache survives rename. Worth measuring?"
./deep inbox "Read the FastMCP retry docs before next agent build" -t reading,mcp

./deep list
```

You'll see your captures with their GUIDs and tags.

## Try it: from your shell

```bash
curl -X POST "http://localhost:8080/api/inbox.php?action=add" \
  -H "X-API-Key: dev-test-key-not-for-production" \
  -d 'content=Caching idea: GUID-keyed cache survives rename. Measure?' \
  -d 'tags=cache,perf'

curl "http://localhost:8080/api/inbox.php?action=list" \
  -H "X-API-Key: dev-test-key-not-for-production"
```

Inbox items are stored under `files/inbox/YYYYMMDD-HHMMSS.md` with
`doc_type='inbox'`. They show up in the sidebar under **Inbox**.

## Try it: from an agent (MCP)

```python
doci_inbox(content="Idea: ...", tags="cache,perf")
doci_inbox_list(limit=20)
doci_get(guid="<the-inbox-guid>")
```

An agent capturing its own intermediate observations into your inbox
is a strong workflow. Whatever it considers "I should remember this
for later" goes into your physical inbox. You triage when you get
back to your screen.

## Promote to a real document

When an inbox item is mature enough to deserve a path:

```bash
curl -X POST "http://localhost:8080/api/inbox.php?action=promote" \
  -H "X-API-Key: dev-test-key-not-for-production" \
  -d 'guid=<inbox-guid>&target=notes/caching-by-guid.md'
```

This:

- Moves the file from `inbox/` to `notes/caching-by-guid.md`.
- Updates `doc_type` from `inbox` to `document`.
- **Keeps the same GUID** -- any link you already had to this item
  still works.
- Commits the move to git.

## When you'd actually use this

- You're reading something, an idea pops up, you don't want to lose
  it. `./deep inbox "..."` from any terminal, in under five seconds.
- An agent is running a long task and notices something tangential.
  Instead of stuffing it into the main report (which would derail the
  output) it drops it into your inbox.
- End of week, you sweep the inbox: delete junk, promote the
  promising items into a folder, leave the maybes parked.

→ Next: [HTML pages](/tour/04-html-pages)
