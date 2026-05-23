# Style Guide

## Fonts
- Primary: `Segoe UI`, fallback `Tahoma, Arial, sans-serif`.
- Usage: regular 400 for body; 600 for labels and buttons; 700 for headings, sidebar brand, and key numbers.

## Color Palette
| Token | Hex | Typical Use |
| --- | --- | --- |
| `--bg` | `#f6f7fb` | App background |
| `--sidebar` | `#1f2937` | Sidebar background |
| `--accent` | `#2563eb` | Primary buttons, links |
| `--text` | `#111827` | Body text |
| `--muted` | `#6b7280` | Secondary text, meta |
| `btn-secondary` | `#374151` | Neutral button |
| `btn-danger` | `#dc2626` | Destructive actions |
| `table-border` | `#e5e7eb` | Card/table borders |
| `table-header` | `#f9fafb` | Table head fill |
| `input-border` | `#d1d5db` | Form fields |
| `notice-bg` | `#ecfeff` | Info banner fill |
| `notice-border` | `#a5f3fc` | Info banner border |
| `notice-text` | `#0e7490` | Info banner text |
| `error-bg` | `#fef2f2` | Error banner fill |
| `error-border` | `#fecaca` | Error banner border |
| `error-text` | `#b91c1c` | Error banner text |
| `login-gradient-1` | `#e0e7ff` | Login background gradient start |
| `login-gradient-2` | `#fef3c7` | Login background gradient end |

## Notes
- Components derive colors from CSS custom properties in `assets/style.css`; keep new UI elements tied to these tokens.
- Maintain high contrast for accessibility: accent on white/`--bg` meets WCAG AA for normal text at current sizes.
