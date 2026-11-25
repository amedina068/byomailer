# BYOMailer Design System — Implementation Spec

> **For Claude Code**: This document defines the complete visual design system for BYOMailer.
> The project uses **Laravel + Inertia.js + Vue 3 + Tailwind CSS**.
> Read this entire document before making any changes. Apply changes incrementally,
> starting with the theme layer, then components, then page-level updates.

---

## 1. Project Context

BYOMailer is a "Bring Your Own Mailer" email marketing platform where users connect their own email provider (AWS SES, Mailgun, Postmark, SMTP). The target audience is **technical founders and enterprise teams** who are cost-conscious and want infrastructure-level control.

### Design Philosophy: "Infrastructure-Grade UI"

The UI should feel like a modern observability/infrastructure tool (think Datadog, Grafana, Vercel dashboard) but with the clarity of a well-designed consumer product. It should NOT look like a generic shadcn/ui app. Every element should communicate: **technical precision, cost transparency, and developer-first thinking.**

### Theme Strategy: Light-First with Dark Sidebar

The main content area uses a **light theme** — this is critical because the email editor has a white background, and switching between dark chrome and white editor creates visual whiplash. However, the **sidebar stays dark** (#111114). This dark sidebar is the visual identity anchor — it makes BYOMailer feel technical and distinct even in a light UI. Think of it like VS Code's dark sidebar with a light editor, or Linear's approach.

### Competitive Positioning

- NOT Mailchimp (no illustrations, no mascots, no playful roundness)
- NOT Kit/ConvertKit (no lifestyle minimalism, no serif fonts)
- NOT Klaviyo (not a dense cockpit, not overwhelming)
- CLOSER TO Resend's precision but with a complete UI, not just an API dashboard
- The look of a tool a CTO opens without embarrassment in an all-hands

---

## 2. Fonts

### Install

Add to the main Blade layout (`<head>` section):

```html
<link href="https://fonts.googleapis.com/css2?family=General+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
```

OR self-host via npm:

```bash
npm install @fontsource-variable/general-sans @fontsource/jetbrains-mono
```

```css
@import '@fontsource-variable/general-sans';
@import '@fontsource/jetbrains-mono/400.css';
@import '@fontsource/jetbrains-mono/500.css';
@import '@fontsource/jetbrains-mono/600.css';
@import '@fontsource/jetbrains-mono/700.css';
```

### Usage Rules

| Context | Font | Weight | Notes |
|---------|------|--------|-------|
| Headings / titles | General Sans | 600-700 | Page titles, section headers, nav items |
| Body text | General Sans | 400-500 | Paragraphs, descriptions, labels |
| ALL numeric data | JetBrains Mono | 400-700 | Open rates, subscriber counts, costs, dates, IDs, any number anywhere |
| Status badges | JetBrains Mono | 500 | "sent", "active", "draft", etc. |
| Code / API keys | JetBrains Mono | 400 | Anything technical or copiable |
| Keyboard shortcuts | JetBrains Mono | 400 | ⌘K, ⌘F, etc. |

**CRITICAL RULE**: Every number in the UI must use `font-mono`. This is the single highest-impact change. Apply `font-family: 'JetBrains Mono', monospace` or Tailwind's `font-mono` class to every element displaying numeric data: metric values, table cells with numbers, percentages, costs, dates, counts, IDs.

---

## 3. Color System

### CSS Variables

Add these to the global CSS file, imported BEFORE Tailwind directives.

**IMPORTANT**: Light is the DEFAULT theme. The sidebar uses its own dark tokens and does NOT follow the main theme. This is intentional — the dark sidebar is BYOMailer's visual identity anchor.

```css
/* ─── LIGHT THEME (default) ─────────────────────────── */
:root {
  /* Backgrounds — warm off-whites, NOT pure white */
  --bm-base:         #F4F4F6;    /* page background */
  --bm-surface:      #FFFFFF;    /* cards, panels */
  --bm-surface-2:    #F9F9FB;    /* nested surfaces, table headers */
  --bm-elevated:     #EFEFF2;    /* hover states, pressed */

  /* Borders — visible but not heavy */
  --bm-border:        #E0E0E6;
  --bm-border-hover:  #CCCCDD;
  --bm-border-strong: #D0D0DA;   /* table headers, strong dividers */

  /* Brand accent — deep olive/lime for contrast on white */
  --bm-accent:       #4A7A00;
  --bm-accent-hover: #3D6600;
  --bm-accent-dim:   rgba(74, 122, 0, 0.06);
  --bm-accent-border: rgba(74, 122, 0, 0.12); /* hairline border on accent-dim chips/badges */
  --bm-accent-text:  #FFFFFF;    /* text ON accent bg */
  --bm-accent-bright: #6AAF00;   /* for badges, highlights where more pop is needed */
  --bm-accent-bright-dim: rgba(106, 175, 0, 0.08);

  /* Semantic colors — saturated but not neon */
  --bm-success:      #1A8A4A;
  --bm-success-dim:  rgba(26, 138, 74, 0.06);
  --bm-success-light: #E8F5EE;   /* bg tint for success banners */
  --bm-error:        #DC3030;
  --bm-error-dim:    rgba(220, 48, 48, 0.05);
  --bm-error-light:  #FDF0F0;
  --bm-warning:      #A86E00;
  --bm-warning-dim:  rgba(168, 110, 0, 0.06);
  --bm-warning-light: #FFF8EB;
  --bm-info:         #2563EB;
  --bm-info-dim:     rgba(37, 99, 235, 0.05);
  --bm-info-light:   #EFF4FF;

  /* Text — near-black, NOT pure #000 (softer, more professional) */
  --bm-text-1:  #111118;    /* primary: headings, values */
  --bm-text-2:  #555566;    /* secondary: body, descriptions */
  --bm-text-3:  #626273;    /* tertiary: labels, hints. Keep 4.5:1 contrast on --bm-base */
  --bm-text-4:  #6E6E80;    /* quaternary: dates, counts, icons, disabled. Keep 4.5:1 contrast on --bm-base */

  /* Radius — still tight */
  --bm-radius-sm: 3px;
  --bm-radius:    4px;
  --bm-radius-md: 6px;

  /* Fonts */
  --bm-font-sans: 'General Sans', -apple-system, BlinkMacSystemFont, sans-serif;
  --bm-font-mono: 'JetBrains Mono', 'SF Mono', 'Fira Code', monospace;

  /* Shadows — subtle, layered (more important in light theme) */
  --bm-shadow-xs: 0 1px 2px rgba(0, 0, 0, 0.04);
  --bm-shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
  --bm-shadow:    0 2px 8px rgba(0, 0, 0, 0.06), 0 1px 3px rgba(0, 0, 0, 0.04);
  --bm-shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.08), 0 2px 8px rgba(0, 0, 0, 0.04);

  /* ── SIDEBAR — always dark, separate token namespace ── */
  --bm-sidebar:         #111114;
  --bm-sidebar-surface: #1A1A1E;
  --bm-sidebar-border:  #2A2A30;
  --bm-sidebar-text-1:  #ECECEC;
  --bm-sidebar-text-2:  #888888;
  --bm-sidebar-text-3:  #555555;
}
```

### Key Design Decision: Dark Sidebar

The sidebar ALWAYS uses `--bm-sidebar-*` tokens, never the main `--bm-*` tokens. This means:

- Sidebar background: `--bm-sidebar` (#111114) — always dark
- Sidebar text: `--bm-sidebar-text-1/2/3` — light text on dark
- Sidebar borders: `--bm-sidebar-border` (#2A2A30)
- Sidebar active indicator: `--bm-accent-bright` (#6AAF00) — brighter lime that pops on dark
- Sidebar nav badges: `--bm-accent-bright` text on rgba dim bg

The main content area uses the standard `--bm-*` tokens which are light.

This creates a visual split (like VS Code, Linear, Notion) that:
1. Gives BYOMailer a distinct identity even in light mode
2. Makes the email editor (white bg) feel native to the content area
3. The sidebar acts as a constant brand anchor

### Global Styles to Add

```css
body {
  font-family: var(--bm-font-sans);
  background-color: var(--bm-base);
  color: var(--bm-text-1);
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--bm-border); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--bm-border-hover); }

::selection {
  background: var(--bm-accent);
  color: var(--bm-accent-text);
}
```

### Tailwind Config Extension

Merge this into the existing `tailwind.config.js` under `theme.extend`:

```js
colors: {
  bm: {
    base:          'var(--bm-base)',
    surface:       'var(--bm-surface)',
    'surface-2':   'var(--bm-surface-2)',
    elevated:      'var(--bm-elevated)',
    border:        'var(--bm-border)',
    'border-hover':'var(--bm-border-hover)',
    accent:        'var(--bm-accent)',
    'accent-hover':'var(--bm-accent-hover)',
    'accent-dim':  'var(--bm-accent-dim)',
    'accent-text': 'var(--bm-accent-text)',
    'accent-bright': 'var(--bm-accent-bright)',
    'accent-bright-dim': 'var(--bm-accent-bright-dim)',
    success:       'var(--bm-success)',
    'success-dim': 'var(--bm-success-dim)',
    'success-light': 'var(--bm-success-light)',
    error:         'var(--bm-error)',
    'error-dim':   'var(--bm-error-dim)',
    'error-light': 'var(--bm-error-light)',
    warning:       'var(--bm-warning)',
    'warning-dim': 'var(--bm-warning-dim)',
    'warning-light': 'var(--bm-warning-light)',
    info:          'var(--bm-info)',
    'info-dim':    'var(--bm-info-dim)',
    'info-light':  'var(--bm-info-light)',
    // Sidebar (always dark)
    sidebar:         'var(--bm-sidebar)',
    'sidebar-surface': 'var(--bm-sidebar-surface)',
    'sidebar-border': 'var(--bm-sidebar-border)',
  },
},
textColor: {
  bm: {
    1: 'var(--bm-text-1)',
    2: 'var(--bm-text-2)',
    3: 'var(--bm-text-3)',
    4: 'var(--bm-text-4)',
    // Sidebar text (always light-on-dark)
    'sidebar-1': 'var(--bm-sidebar-text-1)',
    'sidebar-2': 'var(--bm-sidebar-text-2)',
    'sidebar-3': 'var(--bm-sidebar-text-3)',
  },
},
fontFamily: {
  sans: ['General Sans', ...defaultTheme.fontFamily.sans],
  mono: ['JetBrains Mono', 'SF Mono', 'Fira Code', ...defaultTheme.fontFamily.mono],
},
borderRadius: {
  'bm-sm': '3px',
  'bm':    '4px',
  'bm-md': '6px',
},
fontSize: {
  'xxs':  ['0.625rem',  { lineHeight: '0.875rem' }],    // 10px
  'xs':   ['0.6875rem', { lineHeight: '1rem' }],        // 11px
  'sm':   ['0.75rem',   { lineHeight: '1.125rem' }],    // 12px
  'base': ['0.8125rem', { lineHeight: '1.375rem' }],    // 13px
  'lg':   ['0.9375rem', { lineHeight: '1.5rem' }],      // 15px
},
boxShadow: {
  'bm-xs': 'var(--bm-shadow-xs)',
  'bm-sm': 'var(--bm-shadow-sm)',
  'bm':    'var(--bm-shadow)',
  'bm-lg': 'var(--bm-shadow-lg)',
},
```

---

## 4. Iconography

### Use Phosphor Icons — NOT Lucide, NOT Heroicons

Phosphor has a different stroke weight and is not associated with the shadcn ecosystem. This is instant visual differentiation.

**Install** — add to `<head>`:

```html
<script src="https://unpkg.com/@phosphor-icons/web@2.0.3"></script>
```

OR as Vue components:

```bash
npm install @phosphor-icons/vue
```

**Usage (web font approach)**:

```html
<i class="ph ph-envelope-simple"></i>
<i class="ph ph-chart-line-up"></i>
<i class="ph ph-trend-up"></i>
```

**Usage (Vue component approach)**:

```vue
<script setup>
import { PhEnvelopeSimple, PhTrendUp } from '@phosphor-icons/vue'
</script>
<template>
  <PhEnvelopeSimple :size="18" />
</template>
```

### Common Icon Mapping (Lucide/Heroicons → Phosphor)

| Old | Phosphor | Context |
|-----|----------|---------|
| Mail | `ph-envelope-simple` | Campaigns, emails |
| Users | `ph-users-three` | Subscribers |
| Settings / Gear | `ph-gear-six` | Settings |
| Search | `ph-magnifying-glass` | Search |
| Plus | `ph-plus-bold` | Create new |
| ChevronDown | `ph-caret-down` | Dropdowns |
| ChevronRight | `ph-caret-right` | Navigation |
| ArrowUp/TrendingUp | `ph-trend-up` | Positive metric delta |
| ArrowDown/TrendingDown | `ph-trend-down` | Negative metric delta |
| Copy | `ph-copy` | Copy to clipboard |
| ExternalLink | `ph-arrow-square-out` | External links |
| Key | `ph-key` | API keys |
| Globe | `ph-globe-simple` | Domains |
| BarChart | `ph-chart-bar` | Analytics |
| Zap / Lightning | `ph-lightning` | Performance, SES |
| CheckCircle | `ph-check-circle` | Success, verified |
| XCircle | `ph-x-circle` | Error |
| AlertTriangle | `ph-warning` | Warnings |
| Filter | `ph-funnel-simple` | Filters |
| MoreHorizontal | `ph-dots-three` | More menu |
| Plug | `ph-plug` | Providers/integrations |
| Activity / Pulse | `ph-pulse` | Live activity |
| Send | `ph-paper-plane-tilt` | Send action |
| List | `ph-list-dashes` | Logs |
| FlowArrow | `ph-flow-arrow` | Automations |
| Shield | `ph-shield-check` | Domain health |
| PiggyBank | `ph-piggy-bank` | Cost savings |
| CursorClick | `ph-cursor-click` | Click events |
| UserPlus | `ph-user-plus` | New subscriber |
| ArrowUUpLeft | `ph-arrow-u-up-left` | Bounce |

Browse all at: https://phosphoricons.com — always use the `regular` weight.

Icon sizing: **20px** in navigation, **16px** inline with text, **14px** in table rows and badges.

---

## 5. Component Specifications

### 5.1 Buttons

Four variants. All use `rounded-bm` (4px). No gradients. No border-radius > 4px. Transition: 100ms ease.

**Primary** (accent action):
```
bg: var(--bm-accent)
text: var(--bm-accent-text)  (dark on lime)
hover: filter brightness(0.9)
padding: 7px 14px
font-size: 12px, font-weight: 600
```

**Secondary** (bordered):
```
bg: transparent
text: var(--bm-text-2)
border: 1px solid var(--bm-border)
hover: border-color var(--bm-border-hover), text var(--bm-text-1)
```

**Ghost** (text-only):
```
bg: transparent
text: var(--bm-text-3)
hover: text var(--bm-text-2)
no border
```

**Danger** (destructive):
```
bg: var(--bm-error-dim)
text: var(--bm-error)
border: 1px solid rgba(255,74,74,0.2)
hover: border opacity increases
```

### 5.2 Metric Cards

The primary dashboard element. Used for displaying KPIs.

```
Container:
  bg: var(--bm-surface)
  border: 1px solid var(--bm-border)
  border-radius: 4px
  padding: 18px 16px
  box-shadow: var(--bm-shadow-xs)    ← subtle shadow adds depth in light theme
  hover: border-color var(--bm-border-hover), box-shadow var(--bm-shadow-sm)

Label:
  font: General Sans
  size: 10px
  weight: 600
  color: var(--bm-text-3)
  text-transform: uppercase
  letter-spacing: 1px

Value:
  font: JetBrains Mono (ALWAYS)
  size: 26px
  weight: 700
  color: var(--bm-text-1)
  letter-spacing: -0.5px

Delta (change indicator):
  font: JetBrains Mono
  size: 11px
  weight: 600
  color: var(--bm-success) if positive, var(--bm-error) if negative
  Includes trend icon (ph-trend-up or ph-trend-down)

Period label:
  size: 10px
  color: var(--bm-text-4)
  text: "30d", "7d", etc.
```

### 5.3 Data Tables

Tables should feel like an infrastructure dashboard, not a spreadsheet.

```
Borders: HORIZONTAL ONLY — never vertical borders between columns
Header row:
  bg: var(--bm-surface-2)    ← subtle off-white, not stark contrast
  font-size: 10px
  font-weight: 600
  color: var(--bm-text-3)
  text-transform: uppercase
  letter-spacing: 1px
  border-bottom: 1px solid var(--bm-border-strong)

Data rows:
  font-size: 12px
  color: var(--bm-text-2)
  border-bottom: 1px solid var(--bm-border)
  padding: 11px 16px
  hover: background var(--bm-surface-2)

Numeric cells:
  font-family: JetBrains Mono (ALWAYS)
  text-align: right for numbers

Campaign name cells:
  color: var(--bm-text-1)
  font-weight: 500
  May include an icon to the left
```

### 5.4 Status Badges

Inline status indicators with a colored dot.

```
Container:
  display: inline-flex
  align-items: center
  gap: 5px
  font: JetBrains Mono, 10-11px, weight 500
  padding: 2px 8px
  border-radius: 3px

States & colors:
  sent/delivered → text: var(--bm-success),  bg: var(--bm-success-light)
  active         → text: var(--bm-info),     bg: var(--bm-info-light)
  scheduled      → text: var(--bm-warning),  bg: var(--bm-warning-light)
  pending        → text: var(--bm-warning),  bg: var(--bm-warning-light)
  draft          → text: var(--bm-text-3),   bg: var(--bm-base)
  bounced/failed → text: var(--bm-error),    bg: var(--bm-error-light)

Dot: 5px × 5px circle, same color as text, before the label
```

### 5.5 Panels / Cards

Section wrapper for content groups.

```
Container:
  bg: var(--bm-surface)
  border: 1px solid var(--bm-border)
  border-radius: 6px (this is the MAX radius in the entire UI)
  box-shadow: var(--bm-shadow-xs)    ← subtle shadow for depth in light theme
  overflow: hidden

Header (optional):
  padding: 14px 16px
  border-bottom: 1px solid var(--bm-border)
  display: flex, justify-between, align-center
  Title: 12px, weight 600, color var(--bm-text-1)
  Count badge (optional): font-mono, 10px, color var(--bm-text-3), bg var(--bm-base), border 1px var(--bm-border), rounded 3px

Body:
  padding: 16px (or 0 if content like a table bleeds edge-to-edge)
```

### 5.6 Cost Banner (Signature Element)

**This is BYOMailer's most important UI element.** It shows the user how much they're saving vs traditional ESPs. It should ALWAYS be visible on the main dashboard.

```
Container:
  display: flex, justify-between, align-center
  padding: 12px 16px
  bg: var(--bm-success-light)   ← subtle green tint, NOT plain white
  border: 1px solid rgba(26, 138, 74, 0.12)
  border-radius: 4px

Content:
  Lightning icon (ph-lightning) in success color
  "This month via SES: $X.XX" — cost in font-mono, bold, success green
  "vs $XXX on Mailchimp for same volume" — competitor price with strikethrough in error color

Right side:
  "View breakdown →" link in success color
```

### 5.7 Sidebar Navigation

```
Width: 220px
Background: var(--bm-sidebar)  ← ALWAYS DARK regardless of theme
Border-right: none (dark sidebar blends, no visible border needed)

Logo area:
  padding: 20px 18px
  border-bottom: 1px solid var(--bm-sidebar-border)
  Logo mark: 28x28px square, bg var(--bm-accent-bright), rounded 3px, "B" in mono bold, white text
  Logo text: "BYOMailer" in 15px bold, color var(--bm-sidebar-text-1)

Section labels:
  font-size: 10px, weight 600
  color: var(--bm-sidebar-text-3)
  letter-spacing: 1.5px
  text-transform: uppercase
  padding: 16px 10px 6px

Nav items:
  padding: 8px 10px
  border-radius: 4px
  font-size: 13px, weight 500
  color: var(--bm-sidebar-text-3)
  hover: color var(--bm-sidebar-text-2), bg rgba(255,255,255,0.04)
  active: color var(--bm-sidebar-text-1), bg rgba(255,255,255,0.06)
  active indicator: 3px wide × 16px tall bar on left edge, color var(--bm-accent-bright), border-radius 0 2px 2px 0
  hover: color var(--bm-text-2), bg rgba(255,255,255,0.025)
  active: color var(--bm-text-1), bg rgba(255,255,255,0.03)
  active indicator: 3px wide × 16px tall bar on left edge, color var(--bm-accent), border-radius 0 2px 2px 0

Icons: Phosphor regular, 18px, with 20px width container for alignment, color inherits from nav-item
Badge (optional): font-mono 10px, color var(--bm-accent-bright), bg rgba(106,175,0,0.12), padding 2px 6px, rounded 3px
Keyboard shortcut (optional): font-mono 9px, color var(--bm-sidebar-text-3), bg rgba(255,255,255,0.06), border 1px var(--bm-sidebar-border), margin-left auto
```

### 5.8 Empty States

**Do NOT use illustrations or cute graphics.** BYOMailer empty states show code/CLI examples.

```
Container:
  border: 1px dashed var(--bm-border)
  border-radius: 6px
  padding: 24px
  text-align: center

Message:
  "No campaigns yet" in 12px, color var(--bm-text-3)

Code block:
  bg: var(--bm-sidebar)    ← uses dark sidebar color for contrast, looks like a terminal
  border: 1px solid var(--bm-sidebar-border)
  border-radius: 4px
  padding: 12px 16px
  font: JetBrains Mono 11px
  color: #4AFF91    ← bright green on dark bg
  content: a CLI command like  $ byom campaign create --name "Welcome"

CTA below:
  "or use the dashboard → Create Campaign" in 11px
  "Create Campaign" in accent color, clickable
```

### 5.9 Activity Feed Items

```
Container:
  padding: 12px 16px
  border-bottom: 1px solid var(--bm-border)
  display: flex, gap 12px
  hover: bg var(--bm-surface-2)

Icon container:
  30x30px, border-radius 4px
  bg: semantic -light color, icon in semantic color
  Types:
    send/success  → bg: var(--bm-success-light), icon color: var(--bm-success)
    bounce/error  → bg: var(--bm-error-light),   icon color: var(--bm-error)
    subscribe     → bg: var(--bm-info-light),     icon color: var(--bm-info)
    click/accent  → bg: var(--bm-accent-bright-dim), icon color: var(--bm-accent)
    unsubscribe   → bg: var(--bm-warning-light),  icon color: var(--bm-warning)

Text:
  font-size: 12px, color var(--bm-text-2)
  Bold names/subjects in var(--bm-text-1), weight 500

Timestamp:
  font: JetBrains Mono, 10px
  color: var(--bm-text-4)
  format: "2m ago", "5m ago", etc.
```

### 5.10 Domain Health Widget

```
Section label:
  10px, uppercase, letter-spacing 1px, color var(--bm-text-3)

Each row:
  display flex, justify-between
  padding: 6px 0

Check name (left):
  font: JetBrains Mono, 11px, color var(--bm-text-2)
  values: "SPF", "DKIM", "DMARC", "Reputation"

Status (right):
  font: JetBrains Mono, 10px
  pass: color var(--bm-success), icon ph-check-circle 12px
  fail: color var(--bm-error), icon ph-x-circle 12px
```

---

## 6. Interaction & Motion

### Transitions
- **All interactive elements**: 100-150ms ease. Never longer.
- **No spring/bounce animations** — those are consumer, not infrastructure.
- **Hover on cards**: border-color change only (border → border-hover).
- **Hover on buttons**: brightness shift (primary) or border/text color change (secondary).
- **Hover on table rows**: very subtle bg change rgba(255,255,255,0.015).

### Page Load Animation
- Staggered fade-in-up on dashboard elements.
- 0.3s duration, ease timing, 50ms stagger between elements.
- Apply to: metric cards, panels, banners — NOT to every element.

```css
@keyframes fadeInUp {
  from { opacity: 0; transform: translateY(6px); }
  to   { opacity: 1; transform: translateY(0); }
}
```

### Copy-to-Clipboard
- Add copy buttons on: API keys, domain names, tracking URLs, subscriber IDs, campaign IDs.
- Icon: `ph-copy` in var(--bm-text-4), hover changes to var(--bm-accent).
- On copy: briefly flash icon to `ph-check` in success color, revert after 1.5s.

---

## 7. Layout Specifications

### Dashboard Page Layout

```
┌─────────┬──────────────────────────────────────────┐
│         │  Topbar (breadcrumb, search, CTA)        │
│         ├──────────────────────────────────────────┤
│ Sidebar │  Cost Banner                             │
│  220px  ├──────────┬──────────┬─────────┬─────────┤
│         │ Metric 1 │ Metric 2 │Metric 3 │Metric 4 │
│         ├──────────┴──────────┴─────────┴─────────┤
│         │                      │                   │
│         │  Chart (8-col)       │ Activity Feed     │
│         │                      │  (4-col)          │
│         ├──────────────────────┤                   │
│         │                      ├───────────────────┤
│         │  Campaign Table      │ SES Cost Widget   │
│         │                      ├───────────────────┤
│         │                      │ Domain Health     │
│         │                      │                   │
└─────────┴──────────────────────┴───────────────────┘
```

- Sidebar: fixed, 220px width
- Content: scrollable, padding 24px 28px
- Metrics row: 4-column grid, 12px gap
- Below metrics: 2-column layout — left ~60%, right 340px, 16px gap
- Topbar: fixed within main, 14px vertical padding

---

## 8. Implementation Order

Follow this order to minimize risk and maximize visual impact:

### Phase 1: Theme Foundation (do first, affects everything)
1. Add the CSS variables to global CSS (light-first `:root` with sidebar tokens)
2. Update tailwind.config.js with the bm-* extensions (including sidebar colors)
3. Add font imports to blade layout (General Sans + JetBrains Mono + Phosphor)
4. Set `body` styles (font-family, background, color, antialiasing)
5. Add scrollbar and selection styles

### Phase 2: Global Visual Changes (high impact, fast)
1. Find-and-replace `rounded-lg`, `rounded-xl` → `rounded-bm` or `rounded-bm-md`
2. Find-and-replace gray color classes → bm equivalents (see migration cheat sheet below)
3. Add `font-mono` class to every element displaying a number
4. Swap existing icon library imports → Phosphor equivalents
5. Add `shadow-bm-xs` to all cards and panels
6. **Sidebar**: Change sidebar bg to `bg-bm-sidebar`, text to `text-bm-sidebar-*` tokens. The sidebar must stay dark even though the content area is light.

### Phase 3: Component Updates
1. Update button components to match the 4-variant spec
2. Update/create metric card component (with shadow for light theme)
3. Update table styling (horizontal borders only, `bg-bm-surface-2` header)
4. Create status badge component (using `--bm-*-light` bg colors)
5. Update panel/card wrapper component

### Phase 4: Page-Level Features
1. Add CostBanner to dashboard (uses `bg-bm-success-light` background)
2. Update sidebar to match spec (dark bg, accent-bright active indicator)
3. Add domain health widget
4. Update empty states (dark code block using sidebar colors, not illustrations)
5. Add copy-to-clipboard on relevant elements

### Class Migration Cheat Sheet

| Old class | New class | Notes |
|-----------|-----------|-------|
| `rounded-md` | `rounded-bm` | 4px |
| `rounded-lg` | `rounded-bm-md` | 6px max |
| `rounded-xl`, `rounded-2xl` | `rounded-bm-md` | 6px is max everywhere |
| `bg-white` | `bg-bm-surface` | Cards, panels |
| `bg-gray-50`, `bg-gray-100` | `bg-bm-base` or `bg-bm-surface-2` | Page bg / nested surfaces |
| `bg-gray-200` | `bg-bm-elevated` | Hover states |
| `border-gray-200`, `border-gray-300` | `border-bm-border` | Default borders |
| `text-gray-900`, `text-black` | `text-bm-1` | Primary text |
| `text-gray-600`, `text-gray-700` | `text-bm-2` | Body text |
| `text-gray-400`, `text-gray-500` | `text-bm-3` | Labels, hints |
| `text-gray-300` | `text-bm-4` | Disabled |
| `text-blue-600`, `text-indigo-600` | `text-bm-accent` | Accent color |
| `text-green-600` | `text-bm-success` | Success |
| `text-red-600` | `text-bm-error` | Error |
| `text-yellow-600`, `text-amber-600` | `text-bm-warning` | Warning |
| `bg-blue-600` (buttons) | `bg-bm-accent` | Primary action |
| `hover:bg-blue-700` | `hover:bg-bm-accent-hover` | Primary hover |
| `bg-green-50` | `bg-bm-success-light` | Success tint bg |
| `bg-red-50` | `bg-bm-error-light` | Error tint bg |
| `shadow-sm` | `shadow-bm-xs` | Subtle card shadow |
| `shadow` | `shadow-bm-sm` | Hover shadow |

**For sidebar elements only:**

| Old class | New class |
|-----------|-----------|
| `bg-gray-900` (sidebar) | `bg-bm-sidebar` |
| `text-white` (sidebar) | `text-bm-sidebar-1` |
| `text-gray-400` (sidebar) | `text-bm-sidebar-2` |
| `text-gray-500` (sidebar) | `text-bm-sidebar-3` |
| `border-gray-700` (sidebar) | `border-bm-sidebar-border` |

---

## 9. Hard Rules (Never Break These)

1. **NEVER** use border-radius > 6px on any element
2. **NEVER** use a gradient *fill* on a button, card, surface, or text. The only gradients permitted anywhere are CSS-*mask* fades and the faint hairline `.bm-soft-grid` texture on marketing backdrops — texture, not a visible color wash. The product UI stays gradient-free entirely.
3. **NEVER** use illustrations, mascots, or cartoon graphics in empty states
4. **NEVER** use Inter, Geist, Roboto, or system-ui as primary font
5. **NEVER** use Lucide or Heroicons (use Phosphor)
6. **NEVER** add "AI" badges or labels unless the feature genuinely uses AI
7. **NEVER** add confetti, celebration, or bouncy animations
8. **NEVER** make the sidebar light — it is ALWAYS dark (#111114), this is the brand anchor
9. **ALWAYS** display numbers in JetBrains Mono
10. **ALWAYS** use horizontal-only borders on tables
11. **ALWAYS** include copy-to-clipboard on IDs, keys, domains, and URLs
12. **ALWAYS** keep the cost comparison banner visible on the dashboard
13. **ALWAYS** use 100-150ms transitions, never slower
14. **ALWAYS** use light theme as default for the main content area
15. **ALWAYS** use `--bm-sidebar-*` tokens for sidebar elements, never main `--bm-*` tokens
16. **ALWAYS** add `box-shadow: var(--bm-shadow-xs)` to cards/panels (important for depth in light theme)

---

## 10. Reference Dashboard HTML

The primary visual reference is `byomailer-dashboard-light.html`. This is the **source of truth** for the light-first design with dark sidebar. Use it when implementing Vue components.

A dark-only version also exists as `byomailer-dashboard.html` for reference, but the light version is the one to implement.

Both demonstrate:

- Dark sidebar with nav sections, active states, keyboard shortcuts
- Light content area with topbar, search, domain tag, and CTAs
- Cost comparison banner (green tint background)
- 4-column metric cards with mono numbers and subtle shadows
- Stacked bar chart
- Campaign data table with status badges
- Live activity feed
- SES cost widget with savings indicator
- Domain health checks (SPF/DKIM/DMARC)

All colors, spacing, and typography in the light HTML match this spec.

---
