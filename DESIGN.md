# GEOFlow Admin Design Guide

## Direction

Alibaba Cloud-inspired, console-first admin UI for GEOFlow. This is a brand-inspired operational interface, not an Alibaba Cloud clone: use the recognizable control-panel rhythm, color hierarchy, density, and restrained component treatment without using Alibaba Cloud logos or proprietary assets.

## Color System

- Primary action: `#ff6a00` for create, upload, submit, and other high-emphasis actions.
- Primary hover: `#e65f00`.
- Link and informational action: `#0064c8`.
- Link hover: `#004a99`.
- Console background: `#f5f7fa`.
- Surface: `#ffffff`.
- Soft surface: `#fafbfc`.
- Border: `#dcdfe6`; stronger border: `#c7cdd6`.
- Text: `#181c24`; secondary text: `#5f6b7a`; muted text: `#86909c`.
- Success: `#00a870`; warning: `#ff9a00`; danger: `#e34d59`.

## Typography

- Use system sans fonts: Inter if available, then ui-sans-serif, system-ui, `Segoe UI`, Arial, `Microsoft YaHei`.
- Body text is 14px with 20px line height.
- Page titles are 24px/32px and 700 weight.
- Section titles are 16-18px and 600-700 weight.
- Labels, table headers, and compact metadata use 12-13px with medium weight.

## Layout

- Keep the app console-first: dense, scannable, and task-oriented.
- Use a dark top console bar with white active navigation and orange active indicators.
- Main page background is light gray, with white bordered surfaces.
- Use 16-24px page spacing and 12-16px component spacing.
- Prefer full-width operational sections and compact grids; avoid oversized marketing-style blocks.

## Components

- Buttons: 2px radius, 36-40px height, orange primary, white or outlined secondary.
- Inputs: 2px radius, white background, gray border, blue focus ring, no heavy shadows.
- Cards/panels: white background, 1px border, very light shadow at most, 2px radius.
- Tables: compact rows, soft gray header, strong text headers, blue/orange row hover tint.
- Badges: muted filled backgrounds; status colors remain semantic and do not replace text.
- Modals/dropdowns: white panels, thin border, subtle shadow, square console geometry.

## Interaction

- Hover states should change border/background, not resize elements.
- Focus states use a blue ring with enough contrast.
- Do not rely on color alone for destructive or status actions.
- Keep transitions short, around 120-180ms.

## Do Not

- Do not use rounded, pill-heavy SaaS styling as the default.
- Do not use purple/indigo as the dominant product color.
- Do not add decorative gradient orbs, bokeh, or marketing hero compositions to the admin.
- Do not copy Alibaba Cloud logos, icons, page layouts, or official assets.
