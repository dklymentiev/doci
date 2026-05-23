# HTML pages next to markdown

← [Tour index](/tour/00-start-here)

## What's unusual

In Notion or Confluence, the rendering is owned by the platform. You
get blocks, you don't get an iframe with your own JavaScript. In DOCI
you can drop an `.html` file next to your `.md` files and the system
will render it as a full HTML page in a **sandboxed `<iframe>`**.

The sandbox omits `allow-same-origin`, so the embedded HTML cannot
read DOCI's session, your cookies, or any data outside its own
document. But it can run its own JavaScript, render charts, animate,
or be a small interactive tool.

## Try it

Open the demo: **[demo-dashboard.html](/tour/demo-dashboard.html)**

That file is in this same `tour/` folder, alongside this markdown
document. The router detects the HTML doctype and wraps the file in
the sandbox iframe instead of running it through Parsedown.

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

It is just a file:

```bash
cat > files/projects/dashboard.html << 'EOF'
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

Then register it (or let `scripts/index-documents.php` find it on
the next sweep) and it's a routable, sidebar-listed document like
any other.

→ Next: [Search and tags](/tour/05-search-and-tags)
