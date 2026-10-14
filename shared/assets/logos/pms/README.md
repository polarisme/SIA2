# PMS logo

Poultry Management System logo (feather identity, brown/orange color family).

Expected filenames:

| File | Purpose |
|---|---|
| `pms-logo.png` | Primary logo (used by default) |
| `pms-logo-mark.png` | Icon-only mark, no wordmark (for compact spaces) |
| `pms-logo-light.png` | Light variant, for dark backgrounds |
| `pms-logo-dark.png` | Dark variant, for light backgrounds |
| `pms-favicon.png` | Browser tab icon |

Place files directly in this folder using the names above. `logo_url('pms', variant)`
in `shared/includes/bootstrap.php` resolves to these paths once they exist.
