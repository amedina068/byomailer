# BYOMailer Design System: "Infrastructure-Grade UI"

The BYOMailer dashboard looks like an infrastructure tool, for example a monitoring or a deployment dashboard. It shows technical precision, cost transparency and a developer-first attitude. It must not look like a stock shadcn/ui app.

The main content area is light. The sidebar is always dark (`#111114`). The dark sidebar is the brand anchor. The light content area matches the white background of the email editor.

This file is the short reference. [STYLE_GUIDE.md](STYLE_GUIDE.md) is the full implementation spec, with the exact sizes of each component. If the two files disagree on a color value, `resources/css/app.css` is correct.

## Voice

Short, exact and technical. A label names the thing or the action: "Connect AWS", "Add a domain", "Read the guide". A description gives the fact and the next step.

- Show numbers, costs and states. Do not hide them behind adjectives.
- No mascots, no jokes, no celebration text.
- Do not add an "AI" label unless the feature uses AI.
- The look must not remind a user of Mailchimp (illustrations, playful roundness) or Kit (lifestyle minimalism, serif fonts).

## Color

The tokens are CSS variables on `:root` in `resources/css/app.css`. Use them through `var(--bm-*)`, for example `style="background-color: var(--bm-surface)"` or `bg-[var(--bm-surface)]`.

| Token | Value | Role |
|-------|-------|------|
| `--bm-base` | `#F4F4F6` | Page background |
| `--bm-surface` | `#FFFFFF` | Cards, panels, tables |
| `--bm-surface-2` | `#F9F9FB` | Nested surfaces, table headers, row hover |
| `--bm-elevated` | `#EFEFF2` | Hover and pressed states |
| `--bm-border` | `#E0E0E6` | Default border |
| `--bm-border-hover` | `#CCCCDD` | Border on hover |
| `--bm-border-strong` | `#D0D0DA` | Table headers, strong dividers |
| `--bm-accent` | `#4A7A00` | Primary action, links, focus ring, active sidebar bar |
| `--bm-accent-hover` | `#3D6600` | Primary action on hover |
| `--bm-accent-text` | `#FFFFFF` | Text on the accent color |
| `--bm-accent-bright` | `#6AAF00` | Badges and highlights, and the accent on the dark sidebar |
| `--bm-accent-dim`, `--bm-accent-border` | `rgba(74, 122, 0, 0.06)`, `rgba(74, 122, 0, 0.12)` | Accent tint, and the hairline border of accent chips |
| `--bm-success` / `-light` | `#1A8A4A` / `#E8F5EE` | Sent, delivered, passed checks, the cost banner |
| `--bm-error` / `-light` | `#DC3030` / `#FDF0F0` | Bounced, failed, destructive actions |
| `--bm-warning` / `-light` | `#A86E00` / `#FFF8EB` | Scheduled, pending |
| `--bm-info` / `-light` | `#2563EB` / `#EFF4FF` | Active, new subscribers |
| `--bm-text-1` | `#111118` | Headings, values |
| `--bm-text-2` | `#555566` | Body text, descriptions |
| `--bm-text-3` | `#626273` | Labels, hints |
| `--bm-text-4` | `#6E6E80` | Dates, counts, icons, disabled text |
| `--bm-sidebar` | `#111114` | Sidebar background |
| `--bm-sidebar-surface` | `#1A1A1E` | Surfaces in the sidebar |
| `--bm-sidebar-border` | `#2A2A30` | Sidebar borders |
| `--bm-sidebar-text-1` / `-2` / `-3` | `#ECECEC` / `#888888` / `#555555` | Sidebar text levels |

Each semantic color also has a `-dim` token with a low alpha, for example `--bm-error-dim`.

**Rules**

- The sidebar uses only the `--bm-sidebar-*` tokens and `--bm-accent-bright`. It never uses the light content tokens.
- The shadcn-vue variables (`--background`, `--primary`, `--border`, `--ring` and more) use the same values. `--primary` and `--ring` are `#4A7A00`. `--destructive` is `#DC3030`.
- `app.css` defines light values only. The light theme is the default for the content area.
- `--bm-text-3` and `--bm-text-4` keep a contrast of 4.5:1 on `--bm-base`. Do not make them lighter.
- A status badge uses its semantic color as text and its `-light` token as background.

## Typography

`resources/views/app.blade.php` loads two families. General Sans comes from Fontshare. JetBrains Mono comes from Google Fonts.

| Family | Token | Use |
|--------|-------|-----|
| General Sans 400 to 700 | `--bm-font-sans`, Tailwind `font-sans` | Headings, body text, labels, buttons |
| JetBrains Mono 400 to 700 | `--bm-font-mono`, Tailwind `font-mono` | Every number, status badges, IDs, API keys, code, dates |

**Rules**

- Show every number in JetBrains Mono: counts, rates, costs, dates and IDs.
- Headings use weight 600 or 700. Body text uses weight 400 or 500.
- Small uppercase labels (10px, weight 600, letter spacing 1px, `--bm-text-3`) name metric cards and table columns.
- Do not use Inter, Geist, Roboto or `system-ui` as the primary font.

## Shape & Depth

- **Radius:** `--bm-radius-sm` is 3px (badges). `--bm-radius` is 4px (buttons, inputs, metric cards). `--bm-radius-md` is 6px (panels and cards). 6px is the maximum in the whole UI.
- In Tailwind, `--radius` is `0.375rem`. As a result, `rounded-lg` is 6px, `rounded-md` is 4px and `rounded-sm` is 2px.
- **Borders:** 1px solid `--bm-border`. On hover, a card changes only its border color to `--bm-border-hover`.
- **Shadows:** soft and small. Each card and panel has `--bm-shadow-xs` (`0 1px 2px rgba(0, 0, 0, 0.04)`). `--bm-shadow-sm`, `--bm-shadow` and `--bm-shadow-lg` give more depth for hover, menus and dialogs.
- **Tables:** horizontal borders only. No vertical borders between columns.

## Icons

Use Phosphor icons from `@phosphor-icons/vue`, for example `PhEnvelopeSimple` and `PhTrendUp`. The default weight is `regular`. Use `bold` or `fill` only for a small accent.

Some older components still import `lucide-vue-next`. Do not add Lucide or Heroicons to new code. When you change an old component, move its icons to Phosphor.

## Components

The base components are shadcn-vue components in `resources/js/components/ui`: alert, avatar, badge, breadcrumb, button, card, checkbox, collapsible, confirm-dialog, dialog, dropdown-menu, input, input-otp, label, navigation-menu, progress, separator, sheet, sidebar, skeleton, slider, spinner, switch and tooltip. They read the shadcn-vue variables, so they get the BYOMailer colors.

| Component | Key rules |
|-----------|-----------|
| Primary button | `--bm-accent` background, `--bm-accent-text` text, weight 600, no gradient |
| Secondary button | Transparent, 1px `--bm-border`, `--bm-text-2` text |
| Danger button | `--bm-error-dim` background, `--bm-error` text |
| Metric card | `--bm-surface`, 1px border, 4px radius, `--bm-shadow-xs`, uppercase label, JetBrains Mono value |
| Panel | `--bm-surface`, 1px border, 6px radius, `--bm-shadow-xs`, optional header with a bottom border |
| Status badge | JetBrains Mono, 3px radius, a 5px dot in the text color |
| Empty state | 1px dashed border, a short message and a command or code sample. No illustrations. |
| Copy button | `PhCopy` in `--bm-text-4`. After a copy, it shows a check in `--bm-success` for 1.5 seconds. |
| Cost banner | `--bm-success-light` background. It shows the SES cost of the month in JetBrains Mono. |
| Skeleton | Shows while a deferred Inertia prop loads. |

The dashboard shows the SES setup steps first (`components/dashboard/SesSetupSteps.vue`). Each step shows its state: done, current or to do.

The HTTP error pages (`resources/views/errors/`) load no build assets. `errors/layout.blade.php` copies the token values that it needs into an inline `<style>`. If you change a token in `app.css`, change it there too.

## Spacing & Layout

- The sidebar is fixed on the left. The active sidebar item has a 3px wide, 16px tall accent bar.
- The content area scrolls. STYLE_GUIDE.md gives 24px by 28px of padding.
- Metric cards sit in a 4-column grid with a 12px gap.
- Under the metrics, a wide column holds charts and tables. A narrow column holds the activity feed and the domain health.
- Transitions are 100 to 150 ms with `ease`. A staggered fade-in of 0.3 s can show the dashboard cards on page load.

## Banned

- Border radius more than 6px.
- A gradient fill on a button, card, surface or text. The marketing pages can use the `.bm-soft-grid` texture and mask fades only.
- Illustrations, mascots or cartoons in empty states.
- Confetti, bounce or spring animations.
- A light sidebar.
- Lucide or Heroicons in new code.
- Inter, Geist, Roboto or `system-ui` as the primary font.
- Numbers in General Sans.
- Vertical borders in tables.
