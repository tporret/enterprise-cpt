# Enterprise CPT Verification Matrix

This matrix is the Phase 1 reliability baseline. Run the automated checks before manual checks, then record clean-install and upgrade-install results in release notes.

## Automated Checks

| Area | Command | Expected Result |
| --- | --- | --- |
| JavaScript bundles | `npm run build` | All four WordPress Scripts builds compile successfully. |
| PHP syntax | `composer verify-php` | No syntax errors across plugin PHP, tests, templates, build assets, and vendored PHP files. |
| Schema smoke tests | `composer schema:verify` | Table naming, field group normalization, REST controller harness, definition validation, storage safety, runtime hydration, SSR template, and access-control checks pass. |
| Full gate | `npm run verify` | Build, PHP syntax, and schema smoke tests all pass. |

## Manual WordPress Matrix

| Scenario | Steps | Expected Result | Notes |
| --- | --- | --- | --- |
| Clean activation | Activate Enterprise CPT on a fresh WordPress install with PHP 8.3+. | Plugin activates without fatal errors. Admin notices appear only for intentional environment requirement failures. | Confirm DB user has CREATE and ALTER privileges. |
| CPT save/load | In the CPT manager, save a CPT with a normalized slug and REST support enabled, then reload the manager. | Definition appears after reload and is backed by JSON when the definitions directory is writable, or by the DB buffer in a read-only filesystem. | Check `enterprise_cpt_buffer` only when filesystem write fails. |
| Field group save/load | Save a field group with locations, fields, and optional custom table name, then reload the field group editor. | Saved group reloads with normalized `locations`, `location_rules`, field names, permissions, and storage settings. | Confirm invalid saves are rejected before files/options/tables change. |
| Block registration | Mark a field group as a block and save with a normalized block slug. Open the block inserter. | The dynamic block registers under the Enterprise CPT category and uses the normalized slug. | Duplicate block slugs should fail validation. |
| Frontend rendering | Add the generated block to a post, save, and view the frontend. | The block renders through the template resolver without warnings. Missing upload templates fall back to plugin templates. | Remove stale upload templates when verifying fallback fixes. |
| Read-only filesystem fallback | Temporarily make `definitions/cpt` or `blocks/fields` non-writable, then save a CPT or field group. | Save succeeds through the matching option buffer and diagnostics reports the source as read-only/buffered. | Buffers: `enterprise_cpt_buffer`, `enterprise_cpt_field_group_buffer`, `enterprise_cpt_location_registry_buffer`. |
| Diagnostics command | Run `wp --path=/home/terrencelp/apps/wordpress/wordpress_data enterprise-cpt diagnostics --format=json`. | JSON output includes `cpt_definitions`, `field_group_definitions`, `location_registry`, `upload_templates`, `custom_tables`, and `shadow_sync`. | Run after plugin activation. |
| CLI validation | Run `wp enterprise-cpt save_cpt invalid_cli --definition='{"args":{"supports":"title"}}'`. | Command fails with structured validation fields and does not create a JSON definition. | `--json` is reserved by WP-CLI format handling; use `--definition`. |
| Storage schema migration | Add or change a custom-table field or repeater subfield, then reload WordPress or run activation. | Custom table and repeater child table columns are created without dropping existing data. | Storage signatures include a schema version and nested repeater row schemas. |
| Repeater storage rollback | Save a repeater value while simulating a child-table write failure in a test/staging database. | The child-table replacement rolls back and cache remains untouched until a successful commit. | Covered by `tests/StorageTransactionSafetyTest.php`. |
| Shadow sync drift check | Run `wp enterprise-cpt storage sync-check --group=<field-group-slug> --format=json`. | Reports no drift, or lists exact `post_id`/`meta_key` pairs where custom-table data differs from postmeta shadows. | Custom table data remains the source of truth. |
| Runtime hydration | Load an archive or search result containing posts with custom-table backed fields. | Custom-table parent rows and repeater child rows are cache-warmed in one query per physical table. | Covered by `tests/HydratorRuntimeTest.php`; invalid/duplicate post IDs are ignored before SQL. |
| SSR template fallback | Render a block preview with no theme/upload template, then with a generated upload template. | Missing templates use the plugin fallback; generated uploads templates render schema-aware default markup. | Covered by `tests/TemplateResolverRuntimeTest.php`. |
| REST preview payload limits | Send a render-block request with oversized attributes. | Request fails with `enterprise_cpt_attributes_too_large` and HTTP 413 before template rendering. | Existing limit: 16 KB encoded attributes; cacheable limit: 8 KB attributes and 64 KB HTML. |
| Representative query target | On small, medium, and large content models, run a loop query and render field-backed content. | Custom-table field access should avoid per-field/per-post N+1 reads after `the_posts` hydration. | Expected custom-table read shape: one parent-table query per table plus one child-table query per repeater table for cold caches. |
| Block preview access | Render a block whose field group requires a custom capability the current user lacks. | Preview returns a 404-style missing block response and does not render template output. | Covered by `tests/RestControllerHarnessTest.php`; avoids disclosing restricted field groups. |
| Meta write access | Register fields for a read-only field group, then attempt REST/core meta write authorization. | Reads are allowed for authorized users; `edit_post_meta` auth is denied for read-only or inaccessible groups. | Covered by `tests/FieldRegistrarSecurityTest.php`. |
| Permission schema validation | Save field groups with malformed `minimum_role` or `custom_capability`. | Validation fails before persistence with stable field errors. | Unknown roles fail closed at runtime. |

## REST Endpoint Checklist

Use cookie authentication with an `X-WP-Nonce` created for `wp_rest` when checking these from wp-admin or an API client.

| Endpoint | Method | Required Capability | Expected Verification |
| --- | --- | --- | --- |
| `/wp-json/enterprise-cpt/v1/cpts` | GET | `manage_options` | Returns CPT definitions with source metadata and writable state. |
| `/wp-json/enterprise-cpt/v1/cpts/save` | POST/PUT/PATCH | `manage_options` | Valid definitions save; invalid slugs, malformed args, or oversize payloads return structured errors before persistence. |
| `/wp-json/enterprise-cpt/v1/field-groups` | GET | `manage_options` | Returns visible field groups ordered by saved order and filtered by access level. |
| `/wp-json/enterprise-cpt/v1/field-groups/{slug}` | GET | `manage_options` | Returns a single field group or a 404 for missing/forbidden groups. |
| `/wp-json/enterprise-cpt/v1/field-groups/{slug}` | DELETE | `manage_options` | Deletes JSON and/or DB-buffered definitions and removes the slug from saved order. |
| `/wp-json/enterprise-cpt/v1/field-groups/save` | POST/PUT/PATCH | `manage_options` | Valid definitions save; invalid locations, duplicate fields, unsafe table names, unsupported field types, and duplicate block slugs return structured errors before persistence. |
| `/wp-json/enterprise-cpt/v1/field-groups/reorder` | POST/PUT/PATCH | `manage_options` | Saves order only for existing normalized slugs. |
| `/wp-json/enterprise-cpt/v1/search` | GET | `manage_options` | Supports `post_type`, `taxonomy`, and `user_role` lookups with bounded pagination. |
| `/wp-json/enterprise-cpt/v1/field-groups/for-post-type/{post_type}` | GET | `edit_posts` | Returns post-editor-safe group payloads for the current user. |
| `/wp-json/enterprise-cpt/v1/location-options` | GET | `manage_options` | Returns public post types, taxonomies, and user roles. |
| `/wp-json/enterprise-cpt/v1/render-block` | GET/POST | `edit_posts` plus field-group read access | Returns preview HTML for valid accessible blocks; unknown or forbidden blocks return a 404 payload; rate limits return 429. |

## Release Evidence

For each release candidate, capture:

- `npm run verify` output summary.
- Clean activation result.
- Upgrade activation result from the previous release.
- Diagnostics JSON summary.
- Any manual matrix rows that were skipped and why.

## Rollback Checklist

- Restore the previous tagged `definitions/cpt` and `blocks/fields` JSON definitions before clearing buffers.
- Inspect `enterprise_cpt_buffer`, `enterprise_cpt_field_group_buffer`, and `enterprise_cpt_location_registry_buffer` for admin edits made after the rollback target.
- Export custom tables and postmeta shadows before rolling back storage code; schema upgrades are designed to be non-destructive, not automatically downgraded.
- Run `wp enterprise-cpt storage sync-check --group=<field-group-slug> --format=json` for critical custom-table groups after rollback.
- Review generated upload templates in `wp-content/uploads/enterprise-cpt/templates` because they can continue to override plugin fallback templates after code rollback.