# AJ Core HOA

Homeowners association extension for [AJ Core](../ajcore). Private, for the wozig.com site.

**Status: roadmap only (0.1.0).** The plugin registers with AJ Core and shows a read-only roadmap under *AJ Core > AJCore HOA*. No features are active.

- Plan: [docs/architecture/roadmap.md](docs/architecture/roadmap.md)
- Requires AJ Core with extension API 24 or newer.
- Build a release zip: `./bin/build-release.sh` (see the roadmap's "Releasing" section first).

Layout follows AJ Core RA: `ajcore-hoa.php` (header, boot, registration), `includes/` (the roadmap page; features go here as they are built).
