# Versioning and backup

← [Tour index](/tour/00-start-here)

## Versioning is already on

Every save you make in DOCI -- through the UI, REST, CLI, or MCP --
ends with a `git commit` inside `files/.git/`. There is nothing to
turn on. The very first time the container starts, the entrypoint
script runs `git init` on `files/` and seeds it with whatever content
shipped (this tour, your `index.md`, the docs, etc.) under an initial
commit by author `DOCI`.

From then on:

| Action | What gets committed |
|---|---|
| Create document | `Create <path>` |
| Update content / metadata | `Update <path>` |
| Delete document | `Delete <path>` |
| Inbox add / promote / thread / version | corresponding commit |

The commit author is the **authenticated user** -- `dev` in this
local install, or the user from `Remote-User` / API key in production.

## See the history of a single document

At the bottom of every document page, DOCI shows the last three commits
that touched the file. Click any hash to open the commit (this requires
`origin` to be a known git host; see below).

From a shell:

```bash
docker compose exec doci sh -c 'cd /var/www/html/files && git log -- <path>.md'
```

Or restore an older version:

```bash
docker compose exec doci sh -c 'cd /var/www/html/files && git checkout <hash> -- <path>.md'
```

(The next DOCI save on that file becomes the new HEAD; older state stays
reachable via reflog.)

## Mirror to an external git host

By default `files/.git` has no remote -- everything is local to the
container's volume. To replicate to GitHub / GitLab / Gitea / your own
SSH-served bare repo, add an `origin`:

```bash
docker compose exec doci sh -c \
  'cd /var/www/html/files && git remote add origin https://github.com/me/my-doci-content.git'
```

After this, **DOCI pushes asynchronously after every commit**. The push
is fire-and-forget (`git push origin main &` with stderr to the log);
if the remote rejects or is unreachable, the local commit still
succeeds and the failure goes into `/var/log/doci/app.log`.

For private remotes, set up credentials inside the container:

```bash
docker compose exec doci sh -c \
  'cd /var/www/html/files && git config credential.helper store && \
   echo "https://USER:TOKEN@github.com" > /var/www/.git-credentials && \
   chown www-data:www-data /var/www/.git-credentials'
```

Or use SSH and mount a key into the container.

## Self-host the git UI

If you want a browsable web UI for the history -- diffs, blame, commit
linking -- run a git server alongside DOCI. Any of these work:

- **[Gitea](https://gitea.io)** -- single Go binary, has a Docker
  image. Good default. Set up an empty repo, point DOCI's origin at
  it.
- **[Forgejo](https://forgejo.org)** -- Gitea fork with the same
  workflow.
- **[soft-serve](https://github.com/charmbracelet/soft-serve)** -- a
  tiny SSH-only git server with a TUI.
- **`cgit` / `gitweb`** -- minimal read-only HTML viewers if you only
  need browsing.

DOCI does not bundle any of these. Run them as a separate compose
service on the same host (or anywhere reachable) and treat DOCI's git
remote as a plain git URL.

Once `origin` points at a Gitea instance, the metadata footer on every
DOCI page renders the commit hashes as clickable links into Gitea's
commit viewer (DOCI parses the remote URL to construct the link base
in `lib/git.php::git_get_commit_url_base()`).

## Disable auto-versioning

There isn't a flag for this -- it's load-bearing for thread snapshots,
version diffs, and history. The cheapest thing to do is point `origin`
at `/dev/null` (don't push) and ignore the local commits. Or: don't
back up `files/.git/` and let it churn locally.

## Backup checklist

For anything past a local sandbox:

- [ ] **Push the git repo somewhere.** Add an `origin` to a remote
  you control. The volume containing `files/.git` is rebuildable;
  the remote is your real backup.
- [ ] **Back up Postgres.** Tags, summaries, soft-delete tombstones,
  thread/version metadata, and the `documents` table itself live
  here -- they are NOT derivable from the markdown files alone. Run
  `pg_dump doci > backup.sql` on a cron.
- [ ] **Re-index from disk.** If you lose Postgres, you can rebuild
  most of the row data from `files/` with
  `docker compose exec doci php scripts/index-documents.php`. Tags
  and explicit titles are lost; titles fall back to the first `#`
  heading.
- [ ] **Test restore.** Backup is only as good as the most recent
  test. Spin up a fresh stack from a backup volume + the Postgres
  dump, confirm a document opens and history is intact.

→ Next: [Next steps](/tour/10-next-steps)
