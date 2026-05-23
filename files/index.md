{{embed: .showcase/main.html | height=1180}}

## Get started in 30 seconds

- **Take the tour** → [/tour/00-start-here](/06a1f168-84b3-4461-b7a3-3bea4164c77a)
  — nine short stops, ten minutes, every feature.
- **See a realistic example** → [/demo-farm](/323a09ff-ef13-48c3-9875-175b23f46dd9) — the docs
  of a small fictional organic farm, with KPIs, SOPs, post-mortems,
  and an interactive dashboard.
- **Read the spec** → [/docs/02-spec](/08f2881b-9364-4a12-943b-d6b6efdbc739) — every
  endpoint, every field.

## Run it for real

For a private install you and your agents share over a VPN:

```bash
docker compose up -d
# add 10.x.x.x docs.local to your hosts file
# open http://docs.local
```

That's the whole setup. No reverse proxy, no SSO provider, no public
DNS. [Deployment tour stop](/3d2a42b7-bf8e-4466-b97d-5ff57d892b47) for the production
patterns and the hardening checklist.

---

*DOCI is open source under MIT.
[github.com/dklymentiev/doci](https://github.com/dklymentiev/doci)*
