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

- Read [Vision](/docs/01-vision) for the design intent.
- Read [Functional Spec](/docs/02-spec) for the API surface.
- Read [Architecture](/docs/03-architecture) for how the pieces fit
  together.
- Read [Data Dictionary](/docs/data-dictionary) for the database schema.
