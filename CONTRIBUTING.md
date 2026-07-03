# Contributing

Thanks for your interest! This is a free, GPL-3.0 OpenCart extension.

## Dev environment

A Docker stack (OpenCart 3.0.5.0 + PHP 8.1 + MariaDB) is included.

```bash
docker compose up -d          # start the stack
scripts/dev-provision.sh      # SEO URLs + a 2nd language for testing
scripts/deploy.sh             # copy extension/upload -> the docroot
```

The OpenCart core is downloaded into `./opencart/upload` (git-ignored, GPL
third-party) — see `docker-compose.yml` / the dev notes for the one-time
`cli_install` command.

## Tests

The engine kernel is pure PHP and unit-tested; the binding/apply/walk layers have
DB integration tests that **self-skip** when no database is reachable.

```powershell
scripts\test.ps1              # PowerShell
```
```bash
scripts/test.sh               # Bash / Git Bash
```

Please keep the suite green and add tests for new behavior. CI runs on PHP 8.1
and 8.3.

## Conventions

- Match OpenCart 3.x MVC-L conventions; use `$this->db->escape()` (no bound params
  in OC3's mysqli wrapper).
- Never write catalog fields via `editProduct`/`editCategory` (destructive
  full-rewrite) — use targeted direct SQL + the right `cache->delete(...)` group.
- Never hardcode the `oc_` table prefix — read `DB_PREFIX`.
- Keep the engine kernel (`system/library/spintax/Core/**`) framework-agnostic.

## Pull requests

Open an issue first for anything non-trivial. Keep PRs focused, with a clear
description and green tests. See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for
the design.
