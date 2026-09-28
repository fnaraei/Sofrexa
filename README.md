<p align="center">
  <img src="brand/sofrexa-logo.png" alt="Sofrexa" width="420">
</p>

<p align="center"><b>Restaurant Operations Platform</b></p>

---

Sofrexa is a restaurant operations platform: orders, cashier, kitchen display, stock, customer accounts, staff, reports, QR table ordering and online ordering — in one system.

It is **local-first**. The cashier PC runs the in-house server, so service never stops when the internet does; the web copy on the host stays in sync and receives QR and online orders.

**First deployment:** Basilic Cafe & Restaurant — Girne, Cyprus. That instance is branded *Basilic — powered by Sofrexa*.

## Documents

- **[PLAN.md](PLAN.md)** — plan and roadmap (Persian). Tick boxes show what is done.
- **[docs/screens.md](docs/screens.md)** — all 97 designed screens, each linking to its Figma frame.
- **[docs/design-handoff.md](docs/design-handoff.md)** — tokens → CSS variables, components and layout rules. The build follows Figma exactly.
- **[docs/figma-ids.json](docs/figma-ids.json)** — Figma page, component and variable-collection IDs.
- **[docs/itkafe-schema.md](docs/itkafe-schema.md)** — map of the legacy ItKafe database, for the migration.

**Design (source of truth):** https://www.figma.com/design/1VzYLeeic0ExwgdWt7RbVw

## Folders

| Folder | Contents |
|---|---|
| `brand/` | Sofrexa logo and mark, in dark and light versions |
| `design/` | Overview image of each Figma page |
| `docs/` | Screen index and implementation handoff |

## Branding rule

Sofrexa is the platform; each restaurant is a tenant with its own name and logo.

- **Login screen:** the restaurant logo, with `POWERED BY SOFREXA` underneath.
- **Inside the app** (sidebar, kitchen display): the restaurant's own branding.
- **Customer-facing** (QR menu, online ordering, receipts): the restaurant's branding, with a discreet Sofrexa line.
- **Cover, marketing and a new tenant's sign-in page:** Sofrexa branding.

## Status

Design v1.1 is complete (97 screens, 36 components, 98 icons). Implementation has not started — see the status table in [PLAN.md](PLAN.md).
