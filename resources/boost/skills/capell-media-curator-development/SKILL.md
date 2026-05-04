---
name: capell-media-curator-development
description: Use when editing Capell Media Curator, Awcodes Curator fields, or media migration.
---

# Capell Media Curator

Awcodes Curator integration, media health admin, media fields, and Spatie media migration.

## Look

- `packages/media-curator/src`
- `packages/media-curator/docs`
- `packages/media-curator/README.md`

## Rules

- Do not invent a second media backend; wrap Curator cleanly.
- Migration from Spatie media belongs in actions/commands.
- Keep media health reports read-only unless explicitly mutating.
- Run `vendor/bin/pest packages/media-curator/tests`.
