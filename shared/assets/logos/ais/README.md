# AIS logo

Accounting Information System logo (peso-symbol identity, blue/sky-blue color family).

Expected filenames:

| File | Purpose |
|---|---|
| `ais-logo.png` | Primary logo (used by default) |
| `ais-logo-mark.png` | Icon-only mark, no wordmark (for compact spaces) |
| `ais-logo-light.png` | Light variant, for dark backgrounds |
| `ais-logo-dark.png` | Dark variant, for light backgrounds |
| `ais-favicon.png` | Browser tab icon |

Place files directly in this folder using the names above. `logo_url('ais', variant)`
in `shared/includes/bootstrap.php` resolves to these paths once they exist.
