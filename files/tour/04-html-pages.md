# HTML pages next to markdown

← [Tour index](/tour/00-start-here)

## What's unusual

In Notion or Confluence, the rendering is owned by the platform. You
get blocks, you don't get an iframe with your own JavaScript. In DOCI
you write a normal `.md` document whose CONTENT happens to start with
`<!doctype html>` or `<html>` -- the renderer detects that, wraps the
whole thing in a **sandboxed `<iframe srcdoc=...>`**, and shows it
inside the regular DOCI shell with the sidebar still on the left and
the breadcrumbs at the top.

The sandbox grants `allow-scripts allow-popups allow-forms allow-modals`
and explicitly **omits** `allow-same-origin`, so the embedded HTML runs
in an opaque origin: it cannot read DOCI's session cookie, the parent
DOM, or anything outside its own document.

## Try it

Open the demo: **[demo-dashboard](/tour/demo-dashboard)**

That document is `files/tour/demo-dashboard.md` -- a plain markdown
file in the same `tour/` folder as this page -- but its CONTENT is a
full `<!DOCTYPE html>` page with inline CSS and JS. DOCI sees the HTML
doctype, runs it through `render_html_document()` (in `lib/markdown.php`),
and emits a sandboxed iframe. Title is extracted from the embedded
`<title>` tag.

## Why this matters

An agent that finishes a numerical analysis can deliver:

- A markdown report with prose and tables (good for skim-reading), AND
- An interactive HTML page with sortable tables, simple charts, or
  toggleable views (good when you want to actually poke at the data)

Both live in the same folder, both have stable GUIDs, both are listed
in the same sidebar tree. No "export this report to a dashboard"
roundtrip.

## What HTML pages can do

- All inline `<style>` and `<script>` runs.
- Fetch from public CDNs (subject to your CSP — DOCI's CSP is permissive
  for asset sources by default).
- Render with vanilla DOM, or pull a tiny lib like Chart.js from a CDN.

## What they cannot do

- Talk to DOCI's API. The iframe has no DOCI cookies and no API key.
- Read the URL or content of the parent DOCI page.
- Persist state across reloads except via its own localStorage (which
  is isolated to the iframe origin).

## Writing one

It is just a markdown file with HTML content (note the `.md` extension):

```bash
cat > files/projects/dashboard.md << 'EOF'
<!DOCTYPE html>
<html>
<head>
  <title>Sales by region</title>
  <style>body{font-family:system-ui;padding:2rem}</style>
</head>
<body>
  <h1>Sales by region</h1>
  <canvas id="chart" width="600" height="300"></canvas>
  <script>
    /* draw something */
  </script>
</body>
</html>
EOF
```

Then register it (or let `scripts/index-documents.php` find it on the
next sweep). It's now a routable, sidebar-listed document like any
other markdown file -- but when opened, the page chrome stays the same
and the HTML content renders interactively inside the iframe.

## Two file conventions

- **`.md` file with `<!doctype html>` content** -- wrapped in the DOCI
  shell with the sidebar visible. Use this for "reports with light
  interactivity" that should stay inside the navigation.
- **`.html` file** -- served raw (full-screen, no DOCI chrome). Use
  this only for things you want to load standalone, e.g. embedding in
  an iframe from another tool, or full-screen dashboards.

→ Next: [Search and tags](/tour/05-search-and-tags)
