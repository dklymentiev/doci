{{embed: showcase/hero.html | height=270}}

DOCI hands an AI agent a place to write — and gives you a way to read,
question, and route the result. The agent finishes a job, drops a
report into DOCI, and pastes the link in your chat. You open it, find
a paragraph that looks off, select the sentence and start a thread.
The agent picks up that thread on the next turn and replies inside the
same document.

{{embed: showcase/architecture.html | height=300}}

Everything that lives in DOCI is a plain markdown file in git, with
metadata (GUID, parent links, tags, soft-delete) in Postgres. The
same operations — create, read, thread, snapshot, search — are
available through the **UI**, the **REST API**, the **`deep` CLI**,
and the **MCP server**. Nothing is locked to a single interface.

{{embed: showcase/usage-mix.html | height=270}}

{{embed: showcase/features.html | height=360}}

## Get started in 30 seconds

- **Take the tour** → [/tour/00-start-here](/tour/00-start-here)
  — nine short stops, ten minutes, every feature.
- **See a realistic example** → [/demo-farm](/demo-farm) — the docs
  of a small fictional organic farm, with KPIs, SOPs, post-mortems,
  and an interactive dashboard.
- **Read the spec** → [/docs/02-spec](/docs/02-spec) — every
  endpoint, every field.

## Run it for real

For a private install you and your agents share over a VPN:

```bash
docker compose up -d
# add 10.x.x.x docs.local to your hosts file
# open http://docs.local
```

That's the whole setup. No reverse proxy, no SSO provider, no public
DNS. [Deployment tour stop](/tour/08-deployment) for the production
patterns and the hardening checklist.

---

*DOCI is open source under MIT.
[github.com/dklymentiev/doci](https://github.com/dklymentiev/doci)*
