{{embed: .showcase/main.html | height=1180}}

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
