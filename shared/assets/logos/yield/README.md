# YIELD platform logo

Main platform brand mark, used on the landing page and anywhere the overall
YIELD platform (rather than one specific system) is represented.

Expected filenames:

| File | Purpose |
|---|---|
| `yield-logo.png` | Primary logo (used by default) |
| `yield-logo-mark.png` | Icon-only mark, no wordmark (for compact spaces) |
| `yield-logo-light.png` | Light variant, for dark backgrounds |
| `yield-logo-dark.png` | Dark variant, for light backgrounds |
| `yield-favicon.png` | Browser tab icon |

Place files directly in this folder using the names above. `logo_url('yield', variant)`
in `shared/includes/bootstrap.php` resolves to these paths once they exist.
