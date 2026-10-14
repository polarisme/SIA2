# YIELD platform logos

Centralized location for every logo used across the YIELD platform. One folder per
brand — the main platform mark plus each system's own identity:

```
shared/assets/logos/
├── yield/         main YIELD platform logo/branding
├── pms/           Poultry Management System logo
├── ais/           Accounting Information System logo
└── integrated/    Integrated Poultry Farm Operations & Accounting Management System logo
```

Nothing else in the project should keep its own copy of a logo — always reference
files from here, via the `logo_url()` helper in `shared/includes/bootstrap.php`
(see that function's docblock for the exact filenames it expects per variant).

Each subfolder's own `README.md` lists the exact filenames expected there.
