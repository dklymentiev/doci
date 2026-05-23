# Navigation tour

← [Tour index](/tour/00-start-here)

The small things you'll use without thinking about them once you know
they're there.

## Sidebar tree

The left panel lists every document under `files/`, grouped by folder.
Folders are collapsible -- the `[+]` / `[-]` toggle opens and closes
them. The open/closed state is **persisted to localStorage**, so the
tree comes back the way you left it across reloads.

Sidebar scroll position is also remembered (via `sessionStorage`). If
you scrolled halfway down to find a doc and click into it, you come
back to the same scroll position when you return.

## Breadcrumbs

Top-left of the page. Always shows the path from root to the current
document. Click any segment to jump up the hierarchy. For threads and
versions, the breadcrumb shows the parent document too.

## Stable GUID URL

In the metadata bar at the bottom of any document is the document's
GUID. Click it -- the URL `https://yourdomain/<guid>` is copied to your
clipboard. The chip briefly says **Copied!**.

Paste that URL anywhere -- chat, email, a comment in code. It survives
renaming the file. It survives moving the file into a different folder.
That is the URL you give an agent and forget about.

## Recent files panel

On the homepage (and only the homepage) there's a right-side panel with
**Recent files**: the most recently modified or created documents,
sorted by file mtime. Useful when you've been working and want to pick
up where you left off.

## Header anchors

Every `## H2` and `### H3` in a rendered document gets an auto-generated
ID based on its text. Hover the heading -- a small `#` anchor appears.
Click it -- the URL gets a `#anchor` suffix you can share so the
reader lands exactly on that section.

## Table sorting

When DOCI renders a markdown table with class `.file-list-table` (the
folder card listings, certain meta tables), clicking the header sorts
ascending; clicking again sorts descending. A small arrow indicates the
current sort.

## External links

A link that goes off-domain (anything starting with `http://` or
`https://` not matching your DOCI host) opens in a new tab with
`rel="noopener noreferrer"`. Internal `.html` links go through SPA
navigation -- the page swaps without a full reload.

## Theme toggle

Top-right of the sidebar, button labeled **Theme**. Cycles through the
three palettes: HQ (navy), Mesh (neutral dark), Light (minimal black
& white). Selection persists to localStorage as `hq_theme`. You can
also force a theme with `?theme=light` / `?theme=mesh` / `?theme=hq`
in the URL.

## Back to top

When you scroll past 300px on any page, a floating button appears in
the lower right. Click it for a smooth scroll back to the top. Position
adjusts on window resize.

## Edit and delete (recap)

Top of the breadcrumbs bar of editable documents. Edit opens the
markdown source in an inline textarea; Save commits the change to git
and reloads. Delete soft-deletes the document and redirects to the
parent folder.

→ Next: [MCP, API and CLI](/tour/07-mcp-api-cli)
