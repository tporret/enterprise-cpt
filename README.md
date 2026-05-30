# Enterprise CPT

Enterprise CPT is a JSON-first WordPress plugin for defining custom post types, field groups, storage behavior, and dynamic Gutenberg blocks from version-controlled schemas.

This readme is for developers working in the repository. For plugin users and site administrators, see [readme.txt](readme.txt).

## Overview

The plugin reads two primary schema sources:

- `definitions/cpt/*.json` for custom post types
- `blocks/fields/*.json` for field groups and block-enabled groups

Those definitions drive:

- wp-admin CPT and field-group management screens
- post-meta registration and editor bootstrap payloads
- optional custom-table storage
- dynamic Gutenberg block registration
- shared server-side rendering for editor previews and frontend output

## Current Data Model

### Field groups

Supported field types:

- `text`
- `textarea`
- `number`
- `email`
- `true_false`
- `select`
- `radio`
- `repeater`

Repeater subfields currently support:

- `text`
- `textarea`
- `number`
- `email`
- `image`

The canonical targeting model is `locations`:

```json
{
  "type": "field_group",
  "name": "product_info",
  "title": "Product Info",
  "locations": [
    { "type": "post_type", "values": ["product", "service"] },
    { "type": "user_role", "values": ["administrator", "editor"] }
  ],
  "fields": [
    { "type": "text", "name": "sku", "label": "SKU" }
  ]
}
```

Legacy `post_type` and `location_rules` inputs are still normalized at runtime for backward compatibility.

### Blocks

Any field group can become a dynamic Gutenberg block by setting `"is_block": true`.

Block attributes and rendering are schema-driven:

- `src/Blocks/BlockFactory.php` derives block attributes from field definitions
- `src/Templates/Resolver.php` resolves theme, uploads, and fallback templates
- `templates/generic-block.php` delegates to the shared schema-aware renderer
- `src/Templates/Scaffolder.php` generates uploads templates that also route through the shared renderer

The shared renderer now handles repeater rows and repeater image subfields correctly on the frontend, so nested data no longer degrades to raw `Array` output.

## Architecture Map

- [enterprise-cpt.php](enterprise-cpt.php): bootstrap and runtime wiring
- [src/Plugin.php](src/Plugin.php): orchestration, hook registration, asset bootstrapping, editor data
- [src/Engine/CPT.php](src/Engine/CPT.php): CPT definition loading and persistence
- [src/Engine/FieldGroups.php](src/Engine/FieldGroups.php): field-group normalization, locations, block flags, field schema handling
- [src/Core/FieldRegistrar.php](src/Core/FieldRegistrar.php): meta registration for resolved post-type targets
- [src/Storage](src/Storage): table management, interception, hydration, shadow sync
- [src/Rest](src/Rest): REST controllers for CPTs, field groups, and rendering helpers
- [src/Templates](src/Templates): template resolution and scaffolding
- [assets/src/editor](assets/src/editor): field-group admin application
- [assets/src/post-editor](assets/src/post-editor): post editor field panel
- [assets/src/blocks/universal-field-block](assets/src/blocks/universal-field-block): block editor UI
- [assets/build](assets/build): compiled assets used at runtime

## Build And Verify

Install dependencies:

```bash
composer install
npm install
```

Build runtime assets:

```bash
npm run build
```

Run the repository verification step:

```bash
npm run verify
```

`npm run verify` performs a full JavaScript build, PHP linting, and the Composer schema/runtime/security smoke suite.

## Scripts

NPM scripts:

- `npm run build`
- `npm run build:index`
- `npm run build:editor`
- `npm run build:cpt-manager`
- `npm run build:blocks`
- `npm run start:index`
- `npm run start:editor`
- `npm run start:cpt-manager`
- `npm run verify`

Composer scripts:

- `composer verify-php`
- `composer schema:verify`

## REST Surface

Namespace: `enterprise-cpt/v1`

Primary endpoints:

- `GET /cpts`
- `POST /cpts/save`
- `GET /field-groups`
- `GET /field-groups/{slug}`
- `POST /field-groups/save`
- `DELETE /field-groups/{slug}`
- `GET /location-options`

The field-group endpoints expose location summaries and feed the checkbox-based locations UI for post types, taxonomies, and user roles.

## Storage Notes

The storage layer supports both standard post meta and custom-table storage.

- Standard fields are registered into post meta for the resolved post types
- Repeater values are stored as JSON shadows and can also be projected into relational child tables
- Repeater child-table replacements roll back on failed writes instead of committing partial rows
- Storage schema signatures include nested repeater rows so child-table migrations rerun after subfield edits
- `TableManager` uses the active site prefix for multisite-safe table names
- `Interceptor`, `Hydrator`, and `ShadowSync` keep custom-table reads and compatibility flows aligned
- `Hydrator` primes custom-table caches on `the_posts` to avoid per-field/per-post reads during normal loops

## Runtime Notes

- Block previews enforce encoded attribute limits before template rendering
- Generated upload templates render through schema-aware default markup, while missing templates fall back to `templates/generic-block.php`
- Representative loop checks should show one custom-table parent query per table and one child-table query per repeater table on cold caches

## Security Notes

- Management REST endpoints require authenticated users with `manage_options`
- Post-editor field-group payloads and block previews require `edit_posts` plus field-group read access
- Field meta auth callbacks enforce group-level read/write access, including read-only groups
- Unknown permission roles fail closed and malformed permission settings fail validation before persistence

## Block Rendering Contract

Template resolution order:

1. `wp-content/themes/<theme>/enterprise-cpt/blocks/{slug}.php`
2. `wp-content/uploads/enterprise-cpt/templates/{slug}.php`
3. plugin fallback in `templates/generic-block.php`

Templates receive:

- `$fields` as an associative array of values
- `$group` as the full field-group definition

The default renderer is schema-aware for:

- booleans
- textarea content
- images
- repeaters
- repeater image subfields

## Developer Notes

- Prefer `locations` when adding or editing field-group JSON.
- Keep generated assets in `assets/build` in sync with changes to `assets/src` before shipping.
- If a block fix appears correct in plugin templates but not on the live site, inspect uploads-based block templates first because they can override the plugin fallback.
- Read-only file systems still work through option-buffer fallbacks, but write-path behavior should be tested explicitly.

## Release Checklist

- Keep `enterprise-cpt.php`, `readme.txt`, `package.json`, and `package-lock.json` version metadata aligned before tagging.
- Run `npm run verify` and record the summary in release evidence.
- Verify clean activation on a fresh WordPress site and upgrade activation from the previous tagged release.
- Run `wp enterprise-cpt diagnostics --format=json` after activation and check definition, registry, upload template, custom table, and shadow sync status.
- Confirm `assets/build` reflects the current `assets/src` sources.

## Rollback Notes

- JSON definitions in `definitions/cpt` and `blocks/fields` remain the primary rollback surface; restore the previous tagged files and clear matching option buffers only after confirming no newer admin edits should be preserved.
- Read-only fallback buffers use `enterprise_cpt_buffer`, `enterprise_cpt_field_group_buffer`, and `enterprise_cpt_location_registry_buffer`.
- Custom tables are not destructively downgraded. Before rollback, export custom table data and postmeta shadows, then run `wp enterprise-cpt storage sync-check --group=<field-group-slug> --format=json` on critical groups.
- Generated upload templates under `wp-content/uploads/enterprise-cpt/templates` can override plugin fallback templates; remove or restore them when rolling back block rendering changes.

## Recent Audit Updates

- Refactored admin/editor inline layout styling into shared CSS classes and centralized styles in `assets/src/common/admin-style.css`.
- Validated the full build with `npm run build` across `index`, `editor`, `cpt-manager`, and `blocks` bundles.
- Confirmed the plugin currently does not use WP Interactivity API or WP Abilities API.
- The existing custom permission layer remains the active security model unless future ability/catalog integration is required.

## License

GPL-2.0-or-later
