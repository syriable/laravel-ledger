# Releasing

Internal maintainer notes for cutting a release. Not relevant to package consumers.

## Versioning

This package follows [Semantic Versioning](https://semver.org/) (`MAJOR.MINOR.PATCH`):

- **`MAJOR`** — any public API removal or breaking semantic change.
- **`MINOR`** — additive features that keep `MAJOR` consumers compiling.
- **`PATCH`** — bug fixes that change nothing observable except the bug.

Release candidates use the `-rc.N` suffix (`1.0.0-rc.1`, `1.0.0-rc.2`, …) and are tagged on the same branch as the eventual stable release.

The package has **no `version` field in `composer.json`**. Composer reads versions from git tags. Tagging is the release.

## Pre-release checklist

Run from a clean checkout of `main`:

```bash
git checkout main && git pull
composer install --no-interaction
composer test
composer analyse
composer format -- --test
```

All four must be clean. The CI matrix (SQLite, MySQL 8, Postgres 16, PHP 8.3/8.4, Laravel 11/12) is the canonical signal — confirm green on the merge commit before tagging.

Then verify the release-facing docs:

- [ ] `CHANGELOG.md` has a dated section for the version being cut. `Unreleased` may be empty or list only post-release work.
- [ ] `UPGRADING.md` covers any user-visible behavioural change.
- [ ] The `## Status` line near the top of `README.md` points at the right version.
- [ ] `composer.json` keywords, description, and PHP/Laravel constraints reflect the release.

## Cutting the tag

```bash
git tag -a v1.0.0-rc.1 -m "1.0.0-rc.1"
git push origin v1.0.0-rc.1
```

Annotated tags (`-a`) carry the release author and message. Packagist's GitHub integration picks the tag up automatically and publishes the new version within a minute or two; if you have a manual webhook, kick it.

## Post-release

- [ ] Confirm the version appears on [Packagist](https://packagist.org/packages/syriable/laravel-ledger).
- [ ] Add an empty `## Unreleased` section back to the top of `CHANGELOG.md` if you removed it.
- [ ] Open the upgrade index in `UPGRADING.md` for the *next* version if the next release is expected to have user-visible changes.
- [ ] If this is a release candidate (`-rc.N`), schedule a soak window before tagging the stable version. The 1.0.0-rc soak target is at least four weeks of green CI plus one external adopter report.

## Hot-fix policy

A `PATCH` release is justified when:

- a CI-green release ships a bug that affects correctness, security, or installation, **or**
- a documented invariant is silently violated by the released code.

Hot-fix branches are named `hotfix/<bug>` and merge to `main`. Tag immediately on merge.

## What never triggers a release

- Internal refactors with no API surface change → no tag.
- Pure documentation changes → no tag (consumers do not pin docs versions).
- Test-only changes → no tag.
