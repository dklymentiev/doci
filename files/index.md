# Welcome to DOCI

You are looking at `files/index.md` -- the root document of this
DOCI instance. Edit this file to make it your own.

## What is this?

DOCI is a self-hosted document workspace where:

- Documents live as **plain markdown files** under `files/`.
- Every save is a **git commit**.
- Metadata (titles, tags, threads, versions) lives in **PostgreSQL**.
- The same operations are available through the **UI**, **REST API**,
  the **`deep` CLI**, and an **MCP server** for AI agents.

## Try it

- Browse to [example/welcome](/example/welcome) for a sample document.
- Drop a note into the inbox with `./deep inbox "Quick thought"`.
- Open a thread on a passage by selecting text in the reader and
  clicking the thread icon.
- Snapshot the current state of a document before a big edit.

## Next

- Read [docs/01-vision.md](/../docs/01-vision.md) for the design intent.
- Read [docs/02-spec.md](/../docs/02-spec.md) for the API surface.
- Read [docs/03-architecture.md](/../docs/03-architecture.md) for how
  the pieces fit together.
