# Docs index

Guides for consuming the Elameed Education API. **Frontend devs start at the top.**

| Doc | What it's for |
|---|---|
| **[FRONTEND_INTEGRATION.md](FRONTEND_INTEGRATION.md)** | **Start here.** API-client setup, auth/token flow, and every user journey (visitor → student → teacher → parent → admin) with Vue code snippets + test accounts. |
| [API_ENDPOINTS.md](API_ENDPOINTS.md) | Complete lookup table — **all endpoints** with header tier + request JSON + response JSON. |
| [LANDING_CONTRACT_V2.md](LANDING_CONTRACT_V2.md) | The landing-page contract: 3 layouts, section schemas, dynamic `courses`/`testimonials` resolution. |
| [LANDING_LAYOUTS.md](LANDING_LAYOUTS.md) | How each of the 3 layouts renders the shared section data. |
| [API_REFERENCE.md](API_REFERENCE.md) · [FRONTEND_API_GUIDE.md](FRONTEND_API_GUIDE.md) | **Legacy / early baseline** — kept for reference; the two docs above are the current source of truth. |

**Conventions (all docs):** base URL `…/api/v1`; header tiers (`X-Tenant`, `Authorization: Bearer`); envelopes `{data}` / `{error:{code,message,details}}`; money in integer **minor units**; ISO-8601 dates; Arabic-first / RTL.
