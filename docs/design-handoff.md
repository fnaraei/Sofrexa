# Design handoff — Sofrexa

Figma file: https://www.figma.com/design/1VzYLeeic0ExwgdWt7RbVw (source of truth).
**Rule:** the app is built to match Figma exactly — same tokens, same components, same spacing, on mobile and desktop.
Screen index: [screens.md](screens.md). Node IDs: [figma-ids.json](figma-ids.json).

## 1. Tokens → CSS variables

Every Figma variable has its CSS name set as "code syntax" (Dev Mode shows it). The CSS layer must use exactly these names.

| Figma collection | Figma name | CSS variable |
|---|---|---|
| Color (Light/Dark) | `bg/canvas`, `bg/surface`, `bg/surface-2`, `bg/surface-3`, `bg/brand`, `bg/accent`, `bg/accent-soft`, `bg/{success,danger,warning,attention,info}[-soft]`, `bg/scrim` | `--bg-canvas`, `--bg-surface`, … |
| Color | `text/{primary,secondary,muted,on-brand,on-accent,inverse,accent,success,danger,warning,attention,info}` | `--text-…` |
| Color | `border/{default,strong,accent,success,danger,warning,attention,inverse}` | `--border-…` |
| Color | `icon/{default,strong,muted,on-brand,on-accent,accent,success,danger,warning,attention,info}` | `--icon-…` |
| Primitives | `green/950 … gold/300 …` (hidden) | `--p-green-950`, … |
| Spacing | `space/2 … space/64` | `--space-2 … --space-64` |
| Spacing | `radius/{sm 6, md 10, lg 14, xl 20, 2xl 28, full}` | `--radius-…` |
| Spacing | `size/{touch-s 36, touch 44, touch-l 56, touch-xl 64}` | `--size-…` |

**Themes:** the Light mode is for staff apps (waiter, cashier, admin). The Dark mode is for the kitchen TV and the customer pages (QR menu, online ordering, which match the website). In CSS: `:root` holds Light, and `[data-theme="dark"]` holds Dark.

**Text styles:**
- UI: Manrope — Heading, Body, Label, Overline, Number, KDS, Receipt.
- Customer headings: Cormorant Garamond (Display).
- Persian: Vazirmatn — `FA/*` styles.

**Effects:** `Shadow/Card`, `Shadow/Pop`, `Shadow/Bar`, `Shadow/Glow-Gold` (focus).

## 2. Components (Figma page "Components · اجزا")

| Component | Variants / props | Notes |
|---|---|---|
| Button | Style Primary/Accent/Secondary/Ghost/Danger × Size L56/M44/S36; Label, Show icon, Icon | Primary = dark green, Accent = gold (payment / main customer CTA) |
| IconButton | Style × Size; Icon | always add `aria-label` |
| Badge | Tone Neutral/Accent/Success/Warning/Danger/Attention/Info/Solid; Label, Dot | table and order states |
| Chip | Default/Selected; Label, Count | category and filter rows (horizontal scroll) |
| Segment | Default/Active | segmented control inside a `bg/surface-3` track |
| Toggle, Checkbox | On/Off | touch area 44 |
| SyncStatus | Online/Syncing/Offline | shown in the header of every staff screen |
| Input | Type Text/Search × State Default/Focus/Error | 48px field |
| QtyStepper | M/L | |
| TableTile | Free/Occupied/Bill/QR/Ready/Late | floor map. QR pulses; Late means more than 20 min in the kitchen |
| MenuItemTile | Default/InCart/SoldOut | one tap = +1 |
| OrderLine | New/Sent/Ready/Void | Void stays visible (struck through) for audit |
| ListRow, StatCard, Banner, Toast | | Toast has 5 s "Geri al" (undo) |
| OptionTile | Default/Selected | payment method, courier |
| AppBar, TabItem/TabBar, NavItem/SideNav, SheetHeader, StatusBar (mock only) | | mobile bottom tabs; desktop 264px sidebar |
| Key/Keypad | | PIN and amounts |
| KDS/TicketHeader, KDS/ItemRow | New/Cooking/Late/Ready; Todo/Done | kitchen TV (Dark) |
| Customer/DishCard, Customer/CartBar | Default/InCart | QR and online menu (Dark) |
| Logo/Basilic | S/L | tenant logo: leaf mark + BASILIC wordmark |
| Logo/Sofrexa | Type Full/Mark x Theme Light/Dark | platform logo (raster asset from `brand/`). Theme only swaps the raster; text tokens follow the frame mode |
| Brand/PoweredBy | Theme Light/Dark | `POWERED BY` + Sofrexa mark and wordmark. Sits under the tenant logo on login and customer pages |

## 3. Layout rules

- **Sizes:**
  - Mobile 390×844, with a 16px side gutter. Bottom tab bar is 90px, including the safe area.
  - Desktop 1440×900, with a 264px sidebar and 20–28px padding.
  - TV 1920×1080.
  - Prints are 302px wide (80 mm paper).
- **Touch:** minimum 44px. Primary actions are L (56px) in the bottom action bar on mobile.
- **Sheets:** mobile bottom sheets have top radius 28, a scrim behind, and a grab handle.
- **RTL (Persian):**
  - Mirror row order: rows of tiles are reversed and nav is reversed.
  - Use `FA/*` text styles and Persian digits.
  - Screen W8 and L2 are the reference.
  - In code, use logical CSS properties (`margin-inline-start`, etc.).
- **Offline (local-first):**
  - Show the `SyncStatus` = Offline pill and the warning Banner (W9).
  - Customer QR ordering shows Q4 when the cashier PC has not been seen for more than 60 s.

## 4. Behaviour notes shown in the designs

- **QR ordering:** the first order of a table needs waiter approval (W5). Later orders go straight to the kitchen.
- **Payments (C2/C3):**
  - Choose the currency (TL/£/$/€). The rate shown is the one the cashier entered (C7).
  - Change is always given in TL.
  - Every amount is stored in TL plus the original currency, amount and rate.
- **Void after sending:** needs a reason. It is logged, shown in R6 and printed on the Z report.
- **Online ordering:**
  - Email + password login; the email is verified once with a 6-digit code.
  - Minimum ₺500 (editable in SE3). The cart shows a warning under the minimum (O5).
  - Delivery is free. Payment is on delivery only (cash / card on the courier's POS).
- **Prints (P1–P6):** plain black on white, printed directly without a browser dialog. The kitchen and bar tickets use big text and inverted blocks for "EK SİPARİŞ" and allergy notes.

## 5. Added in design v1.1 (2026-09-28)

New screens are marked 🆕 in [screens.md](screens.md). Build them with the same components; the rules below are the parts that are not obvious from the pictures.

- **Side navigation** has a new item, `Finans` (wallet icon), between `Raporlar` and `Ayarlar`.
- **Tenant profile (SE5/SE6)** is the only source of the restaurant name, logo, address, phones and receipt texts. Nothing in the code may hard-code "Basilic".
- **Backup and restore (SE7–SE9):**
  - Restore is for the manager role only.
  - The user must type `GERİ YÜKLE` to confirm.
  - A backup of the current state is taken automatically before the restore starts.
- **Quick price and stock (M7/M8):**
  - Changes stay in a preview (highlighted cells) until one save.
  - Bulk change: category × percentage × rounding step.
  - Old prices are written to the activity log.
  - Daily stock left empty means unlimited. When it reaches 0 the item becomes "Tükendi" everywhere.
- **Cash drawer (C10/C11):**
  - Every movement is append-only. A mistake is fixed with a reverse entry, never an edit.
  - Opening the drawer without a sale is its own logged event.
  - Cash-drawer expenses also appear in Finance (FI1).
- **Loyalty (CU5, C12):**
  - Points are earned on the amount actually paid, after discounts and points.
  - Tier rates: Bronze 5 %, Silver 7 %, Gold 10 %. 1 point = ₺1. Minimum use is 100 points.
  - ItKafe bonus balances are imported as points.
- **Loyalty tiers (CU5, CU7):**
  - Each tier has a name, a yearly-spend threshold, a discount % and an earn %, all editable. The point value, minimum redemption and expiry are editable too.
  - A nightly job recalculates each customer's tier from the last 12 months of spending.
  - A tier set by hand (CU7) is kept until it is switched back to automatic.
  - If a customer has both a tier discount and a personal discount, the larger one applies.
- **Users (ST8, ST9):**
  - The manager adds, edits and deletes users and resets PINs and passwords.
  - Delete means deactivate: history stays linked.
  - A new PIN is shown once.
  - 3 wrong PINs lock the user for 30 s.
  - Remote (off-site) login needs email + password and is off by default.
- **Roles (ST5)** set the defaults. A person-level exception (ST4) overrides their role.
- **Activity log (ST6/ST7):**
  - Written by the server for every sensitive action: login and failed PIN, void, discount, price change, drawer open, cash in and out, settings change, backup and restore.
  - No edit or delete endpoint exists.
- **Finance:**
  - Supplier purchases are not an expense line in profit and loss (FI3). They reach it as the recipe cost of what was sold (SMM) plus waste. The difference goes to stock value.
  - FI1 is the cash view; FI3 is the accrual view.
- **Online accounts (O1, O2, O7, O8, O9):**
  - Email plus password; the email is the username.
  - A 6-digit code verifies the email before the first order.
  - Password reset uses the same code flow.
  - QR customers never log in.
- **QR approval:** the first QR order of a table session needs waiter approval (W5). The session ends when the bill is settled.
