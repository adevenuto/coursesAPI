# TrainerFlow Design System

A dark-first, high-energy design system for **TrainerFlow** — an all-in-one SaaS platform for personal trainers and coaches (workout programming, nutrition planning, client messaging, progress analytics). Company brief: *courses_api*. The look is a near-black canvas lit by an electric-lime accent and an emerald "aurora" glow — sporty, premium, techy.

## Sources
- **Provided:** one marketing landing-page screenshot — `uploads/7be1d8d967c22a2fc445f45f3736880f.jpg`. This is the single visual ground truth; **no codebase, Figma, or logo file was supplied.** Everything below is derived from that image.
- **Not provided:** brand fonts, logo/SVG assets, color spec, component source. Fonts and the wordmark are substitutions — see caveats.

---

## CONTENT FUNDAMENTALS
- **Voice:** confident, direct, benefit-first. Speaks to the trainer as **"you"** ("Everything *you* need", "kills *your* productivity").
- **Headlines:** short, punchy, often two lines with the **second line in lime** as the payoff — "One platform. / **Unlimited potential.**", "Everything you need to / **coach like a pro**". Sentence case, tight tracking, a period for finality ("…in **one app.**").
- **Eyebrows:** 1–2 word section labels in a pill — "The Problem", "The Solution", "Features" — neutral case, sits above the title.
- **Body:** plain, jargon-light, one idea per sentence. Names pain then relief ("Stop stitching together spreadsheets & WhatsApp").
- **Proof:** big lime numbers + terse labels — "80% Less admin time", "10k+ Clients coached". Metrics do the persuading.
- **Casing:** Sentence case everywhere except the uppercase eyebrow *feel* is achieved via the pill, not caps. Buttons are Title Case ("Start Free Trial").
- **Emoji:** none. Iconography carries all visual shorthand.
- **Vibe:** gym-tech. Motivational without being loud; premium without being corporate.

## VISUAL FOUNDATIONS
- **Color:** near-black green-tinted charcoals (`--ink-900` page → `--ink-700` raised) with a single electric-lime accent (`--lime-500 #8ae63c`) and a supporting emerald (`--emerald-500 #22c55e`). At most the two greens carry the whole system; everything else is neutral. Lime = action, highlight, data, positive; emerald = ambient glow + success.
- **Backgrounds:** the signature is a **radial "aurora"** — emerald/lime glows bleeding from the top-left/top over the near-black (`--grad-aurora`), used on the hero and feature sections. Body sections alternate flat `--ink-900` / `--ink-850`. No photos, no repeating patterns, no noise texture.
- **Type:** display **Sora** (700, tight −0.02/−0.03em) for headlines and metrics; body **Onest** (400–600) at 1.6 line-height; **JetBrains Mono** for stats/hex. Big display-to-body contrast.
- **Cards:** charcoal `--ink-800`, **hairline border** (`rgba(255,255,255,.07)`), **16px** radius, soft drop shadow. Featured cards add an emerald **top radial glow** (`--grad-card-glow`) and a **lime border**. Icons sit in a 40px lime-tinted rounded square.
- **Buttons:** fully-rounded **pills**. Primary = lime **gradient** fill with a lime **glow** shadow and dark ink text; secondary = raised charcoal; ghost = hairline outline; dark = near-black nav pill.
- **Borders & lines:** almost always hairline white at 7–18% opacity; the lime border (`rgba(138,230,60,.45)`) marks accented/active elements.
- **Radii:** 6 / 12 / 16 / 20 / 28, pills at 999. Cards 16, icon tiles 12, buttons pill.
- **Shadows:** soft and dark on the black ground (`--shadow-md/lg`); the distinctive elevation cue is **glow**, not shadow — `--glow-cta` (lime) on primary buttons, `--glow-soft` (emerald) on featured cards. The hero dashboard mock floats with a 3D tilt + heavy shadow.
- **Charts / data:** lime bars with a subtle outer glow; percentages and counts in lime.
- **Animation:** restrained. Buttons ease transform/brightness ~120ms. **Hover:** primary brightens + glow intensifies; cards **lift 3px** and gain a lime border; nav links fade gray→white. **Press:** scale to 0.97 (subtle shrink). No bounces, no big parallax.
- **Transparency/blur:** sticky nav is translucent `rgba(10,11,10,.72)` + `backdrop-filter: blur(14px)`. Otherwise surfaces are opaque.
- **Imagery vibe:** UI-as-imagery (floating product mockups), not photography. Cool/dark with warm-lime highlights.
- **Layout:** centered `1120px` container, `96px` section rhythm, generous grids (4-up problem tiles, 3-up feature grid, 2-up hero/solution splits).

## ICONOGRAPHY
- **Style:** outline line icons, ~2px stroke, rounded caps/joins — consistent with **Lucide**. Used inside lime-tinted 40px rounded squares for features, inline in badges/checklist/stat rows, and as button affordances (arrow-right).
- **Source:** no icon assets were provided. **Substitution:** [Lucide](https://lucide.dev) via CDN (`unpkg.com/lucide`) — matches the stroke weight and rounded style in the screenshot. Swap for the real set if TrainerFlow ships one.
- **Checkmarks:** custom rounded check (lime) / cross (muted) drawn inline in `ChecklistItem` for the before/after lists.
- **Emoji / unicode:** never used as icons.
- **Logo:** no logo file was provided, so **do not treat the mark as canonical.** A placeholder wordmark ("TrainerFlow" + a lime rounded square holding a pulse/heartbeat glyph) is used in nav/footer and `guidelines/brand-wordmark.card.html`. Replace with the official asset when available.

---

## Components
Reusable primitives, grouped under `components/`. Namespace: `window.TrainerFlowDesignSystem_2e5958`.

**core/**
- **Button** — pill action; `primary` (lime gradient + glow), `secondary`, `ghost`, `dark`; sizes sm/md/lg; icons, `href`, `disabled`, `fullWidth`.
- **Badge** — eyebrow / status pill; `neutral` / `lime` / `solid`; optional status dot or icon.
- **Card** — charcoal surface; `glow` (emerald top-glow) and `hover` (lift + lime border).

**content/**
- **SectionHeading** — eyebrow + two-line title with accent second line + lead.
- **StatBlock** — big lime metric over a muted label.
- **FeatureCard** — lime icon tile + title + description, optional badge / media / glow.
- **ChecklistItem** — rounded lime check or muted cross + label (before/after lists).

*Intentional additions:* none beyond what the screenshot demonstrates. No source component library was provided, so the inventory is the set of primitives visible in the landing page.

## UI Kits
- **ui_kits/marketing/** — full recreation of the TrainerFlow landing page (`index.html`): sticky nav, hero + floating dashboard mock, Problem grid, Before/After Solution, Features grid, CTA, footer. Factored into `Hero.jsx` and `Sections.jsx`, composing the primitives above.

## Foundations (Design System tab)
Specimen cards live in `guidelines/` — Colors (lime, emerald, inks, text/semantic), Type (display, headings, body, mono), Spacing (scale, radii), Brand (gradients, aurora, wordmark).

## Root manifest
- `styles.css` — entry point; `@import`s all tokens + fonts. **Consumers link this one file.**
- `tokens/` — `colors.css`, `typography.css`, `spacing.css`, `effects.css`, `fonts.css`.
- `components/` — core/ + content/ primitives (`.jsx` + `.d.ts` + `.prompt.md` + card html).
- `ui_kits/marketing/` — landing page recreation.
- `guidelines/` — foundation specimen cards.
- `thumbnail.html` — homepage tile. `SKILL.md` — Agent-Skill wrapper. `readme.md` — this file.

## Caveats
- **Built from a single screenshot** — spacing/color values are close visual matches, not exact source values.
- **Fonts are substitutes** (Sora / Onest / JetBrains Mono via Google Fonts). Provide the real TrainerFlow font files to swap in.
- **Icons are Lucide** (CDN substitute). **No logo provided** — wordmark is a placeholder.
