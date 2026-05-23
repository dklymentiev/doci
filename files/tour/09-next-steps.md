# Next steps

← [Tour index](/tour/00-start-here)

You finished the tour. Quick mental check -- you should now be able to
answer:

- Where does a document physically live? (file on disk + row in `documents`)
- What makes a link "stable"? (GUID, not path)
- What happens when you start a thread? (auto-snapshot + thread doc)
- Where does an agent's output go? (REST POST or MCP tool, ends up
  as a markdown file with a GUID)
- How do you keep this private? (VPN + hosts file, no public DNS)

## Where to go from here

### Customize for your workflow

- Delete the tour and example content under `files/tour/` and
  `files/example/` once you've internalized it. They were seeded for
  you on first install.
- Edit `files/index.md` to be your actual homepage -- a launcher into
  the folders you'll use, a daily journal entry, whatever fits.
- Pick a top-level folder structure that matches how you think
  (`projects/`, `notes/`, `reading/`, `archive/`, ...). Documents are
  cheap; move them later if it doesn't fit.

### Wire up an agent

This is the unlock. Pick the agent you already use:

- **Claude Code / Claude Desktop:** add `mcp_server.py` to your MCP
  config (see [the MCP tour stop](/tour/07-mcp-api-cli)). The agent
  immediately has 12+ tools for reading and writing.
- **Custom scripts:** point them at the REST API with an `X-API-Key`
  header. Anything that can `curl` can drive DOCI.

The first useful workflow is usually: "agent finishes a job, writes a
report under `projects/<job-name>.md`, drops the GUID in the chat
message." Try that loop once -- it sells the model.

### Run as a real service

Once you're past playing:

- Switch from `docker-compose.dev.yml` to `docker-compose.yml`.
- Set `DOCI_DEBUG=false`, set `DOCI_API_KEY_HASH`, set a strong
  `DOCI_DB_PASS`.
- Put it on a server you control, behind a VPN if it's just for you
  and your agents, behind a reverse proxy with SSO if it's a team.
- Configure backups (`git push` for `files/`, `pg_dump` for Postgres).

### Optional integrations

- **mesh-memory** for semantic search across DOCI documents:
  [github.com/dklymentiev/mesh-memory](https://github.com/dklymentiev/mesh-memory).
  Set `MESH_API_URL` in DOCI's env, and the integration kicks in.
- **AI gateway** for thread responses: set `AI_GATEWAY_URL` to any
  OpenAI-compatible chat-completion endpoint.

### Reset the demo

The seeded `tour/`, `example/`, and any inbox items from the walk-through
can be removed by hand:

```bash
docker compose exec doci rm -rf files/tour files/example
docker compose exec doci-db psql -U doci_app -d doci \
  -c "UPDATE documents SET deleted_at = NOW() WHERE path LIKE 'tour/%' OR path LIKE 'example/%';"
```

Or just leave them -- they're small and don't cost anything.

## Where to read more

- [README.md](https://github.com/dklymentiev/doci) -- repo-level docs
- [Vision](/docs/01-vision) -- the design intent
- [Functional spec](/docs/02-spec) -- every endpoint and field
- [Architecture](/docs/03-architecture) -- how the pieces fit
- [Data dictionary](/docs/data-dictionary) -- the schema

## Where to report issues

[github.com/dklymentiev/doci/issues](https://github.com/dklymentiev/doci/issues)

← [Tour index](/tour/00-start-here)
