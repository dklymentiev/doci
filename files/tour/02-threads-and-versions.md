# Threads and versions

← [Tour index](/06a1f168-84b3-4461-b7a3-3bea4164c77a)

## The model

A **thread** is a discussion attached to a document, optionally pinned
to a specific quoted passage. Internally, a thread is itself a
document -- `doc_type='thread'`, with `parent_guid` pointing at the
document under discussion, and `quote` holding the exact selected text.

A **version** is a snapshot of a document at a point in time. It is also
a document -- `doc_type='version'`, with `original_guid` pointing at
the live document.

Here is the rule that ties them together: **starting a thread auto-snapshots
the parent into a version.** So every thread is anchored not to "the
document" but to a frozen snapshot of it. The conversation never goes
stale just because the original was edited.

## Try it: start a thread

The sentence below is **a real quotable target**. Try this:

> Agents pick up a document, write into it, and link back to the same
> stable GUID across renames.

1. Select that sentence with the mouse.
2. Right-click. Pick **Start Thread** from the context menu.
3. In the modal, type a question like "Is the rename-stability mostly
   useful for chat-links?" -- and optionally tick the AI checkbox
   (Haiku / Sonnet / Opus).
4. Click **Create**.

What just happened:

- A `version` row was inserted, snapshotting the current state of this
  document. The version file lives at
  `files/.data/versions/<orig-guid>/<ver-guid>/index.md`.
- A `thread` row was inserted, with `parent_guid` = the version GUID
  and `quote` = your selected text.
- The thread file lives at
  `files/.data/threads/<ver-guid>/<thread-guid>/index.md`.
- If you ticked AI, a synchronous request was sent to the AI gateway
  and the reply was appended into the thread file.
- You're now looking at the **version** of this page, not the live one.
  The original keeps moving forward; this snapshot is frozen.

Look at the top of the page -- there is a **version bar** with chips
like `Original | DV-1` (DV = initials of `dev`). Click `Original` to
go back to the live document. Click `DV-1` to come back to this
snapshot.

In the markdown body, your quoted sentence now has a clickable block
around it. Hover for a tooltip with the thread author and date; click
to open the thread page.

## Replying

Open the thread page (click the indicator block over your quoted
sentence). At the bottom: a textarea labeled **Reply**.

Type something, press Enter (or click Send). The reply appends to the
thread file. If you have an AI checkbox visible (only on
already-AI threads), tick it to ask Claude to respond again.

## Listing versions and threads

In the version bar at the top, you see every snapshot. Each version
chip shows how many threads it carries. Click into any chip; you land
on that frozen state with its associated threads visible.

Programmatically:

```bash
curl 'http://localhost:8080/api/versions.php?document_guid=<g>' \
  -H "X-API-Key: dev-test-key-not-for-production"
```

Or the MCP tool `doci_versions(document_guid=...)`.

## When you'd actually use this

- An agent ships a long report. You spot a sketchy claim in paragraph
  3. You start a thread on that sentence. The agent's next run reads
  your thread, fixes the claim, and replies in the same thread to
  explain.
- You're reviewing a design doc. Three people each open threads on
  different passages. Nobody overwrites anyone else; each lives in its
  own snapshot.
- You want to compare "what we agreed" (version 1) vs. "what we ended
  up with" (current). Click between version chips, see the diff
  visually, run `git diff` for the byte-exact view.

→ Next: [Inbox capture](/260aaad0-2e9c-416d-b2ed-4c9b2e0ef691)
