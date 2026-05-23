# Documents and editing

← [Tour index](/tour/00-start-here)

## A document is two things

A row in PostgreSQL (`documents` table) **plus** a `.md` file on disk
under `files/`. The row carries the metadata that doesn't fit in
markdown -- a stable UUID, the human path, the parent (for threads),
the original (for versions), tags, soft-delete tombstone.

Read this in the metadata bar at the bottom of any document page:

```
Path: tour/01-documents-and-editing.md  |  GUID: a1b2c3d4-... |  History: a4f8b2, 1f93c, ...
```

Click the GUID -- it copies a stable URL to the clipboard. Even if you
rename the file later, that URL still resolves.

## Editing in the browser

You're authenticated as `dev` (this is the local dev mode -- in
production you'd hit a reverse proxy or pass an API key).

**Try it:**

1. Click **Edit** in the top-right of this page.
2. Append a line: `My note: tested edit on <today>.`
3. Click **Save**.

What just happened:

- DOCI wrote your new content to `files/tour/01-documents-and-editing.md`.
- It made a git commit: `Update tour/01-documents-and-editing.md`.
- The page reloaded with the new content.

Open a shell and run `git log -- files/tour/01-documents-and-editing.md`
in the working tree -- your commit is there. The git working tree IS
the source of truth; Postgres is the index.

## Cancel before saving

If you click Edit and decide not to save, click **Cancel**. The textarea
resets to whatever was loaded. Try to navigate away mid-edit with
unsaved changes -- the browser blocks you with the standard "leave
site?" dialog (`beforeunload`).

## Deleting

Click **Delete**. DOCI sets `deleted_at = NOW()` and commits a
`Delete <path>` marker to git. The file on disk stays put -- you can
recover it from the working tree or from git. The UI hides the document
from listings.

If the document had threads, they're cascaded along with it.

## What you can't do here

Documents are markdown files. There is no rich-text editor, no blocks,
no real-time multiplayer cursors. If two people edit the same file,
last writer wins -- merges resolve in git, not in the UI.

→ Next: [Threads and versions](/tour/02-threads-and-versions)
