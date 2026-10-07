---
name: capell-media-library-development
description: Awcodes Curator integration, media health admin, media fields, and Spatie media migration. Use when editing Capell Media Library, Awcodes Curator fields, or media migration.
---

# Capell Media Library

Awcodes Curator integration, media health admin, media fields, and Spatie media migration.

## Look

- `packages/media-library/src`
- `packages/media-library/docs`
- `packages/media-library/README.md`

## Rules

- Do not invent a second media backend; wrap Curator cleanly.
- Migration from Spatie media belongs in actions/commands.
- Keep media health dashboard reports read-only unless explicitly mutating.
- Verify customisations in the consuming application's test suite.
