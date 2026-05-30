# Enterprise CPT 0.6.0 Release Notes

Release date: 2026-05-29

## User-Facing Changes

- Field group and CPT saves now fail earlier with structured validation errors when definitions contain invalid slugs, locations, fields, storage settings, block slugs, or permission settings.
- Block previews now respect field-group read access and return a missing-block response instead of rendering restricted templates.
- Read-only field groups now allow reads while denying registered post meta writes through core/REST meta authorization.
- Custom-table backed fields are hydrated during normal post loops to reduce repeated field reads.

## Developer-Facing Changes

- Added centralized definition validation through `EnterpriseCPT\Validation\DefinitionValidator`.
- Added storage safety coverage for repeater child-table rollback, nested repeater storage signatures, and image field storage alignment.
- Added runtime coverage for custom-table hydration and SSR template fallback behavior.
- Added access-control coverage for restricted block previews and registered post meta auth callbacks.
- `npm run verify` now runs the JavaScript build, PHP syntax checks, and the Composer schema/runtime/security smoke suite.

## Compatibility Notes

- Minimum PHP remains 8.3 and WordPress compatibility remains tested up to 6.9.
- Unknown `permissions.minimum_role` values now fail validation and fail closed at runtime.
- WP-CLI CPT saves should use `--definition=<json>`; `--json` is reserved by WP-CLI format handling.
- Existing JSON definitions and option-buffered definitions continue to load when valid after normalization.

## Verification Evidence

- `npm run verify` passed for the 0.6.0 release candidate.
- Live Docker smoke check confirmed the plugin is active at version 0.6.0.
- `wp enterprise-cpt diagnostics --format=json` returned writable CPT/field group definitions, writable upload templates, healthy custom tables, and scheduled shadow sync.

## Known Limitations

- Clean-install and upgrade-install checks still need to be recorded against the final tagged artifact before publishing.
- Diagnostics may report buffered definitions or registry entries from prior admin saves; inspect buffers before treating them as release regressions.
- Generated upload templates can continue to override plugin fallback templates after code changes or rollback.

## Rollback Guidance

- Restore previous tagged JSON definitions in `definitions/cpt` and `blocks/fields` before clearing any buffered options.
- Inspect `enterprise_cpt_buffer`, `enterprise_cpt_field_group_buffer`, and `enterprise_cpt_location_registry_buffer` for newer admin edits before deleting buffers.
- Export custom tables and postmeta shadows before rolling back storage code; this release does not provide destructive schema downgrades.
- Run `wp enterprise-cpt storage sync-check --group=<field-group-slug> --format=json` for critical custom-table groups after rollback.
- Remove or restore generated upload templates under `wp-content/uploads/enterprise-cpt/templates` when rolling back block rendering behavior.