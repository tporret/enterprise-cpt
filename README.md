# Enterprise CPT

Enterprise CPT is a JSON-first WordPress plugin for enterprise content modeling.

This repository is developer-focused and documents architecture, APIs, and local build workflows.

## What It Provides

- CPT Manager UI (React + WP components)
- Field Group Manager UI with List View + Editor View
- Standard Field Type Library (Text, Textarea, Number, Email, True/False, Select, Radio, Repeater)
- Repeater Focus Canvas editor with drag-and-drop sorting, pencil/copy/trash icon actions, centered modal with Done/Discard footer, and clone snackbar notification
- Repeater media workflow: bulk image row creation and inline image preview/edit controls
- **Field-to-Block Bridge**: turn any field group into a Gutenberg block with `is_block: true`
- JSON-backed definitions with DB buffer fallback for read-only environments
- Optional custom table storage per field group
- Metadata interception, cache layer, hydrator, and shadow sync
- WP-CLI commands for registration and storage migration
- REST API under `enterprise-cpt/v1`

## Runtime Requirements

- WordPress 6.0+
- PHP 8.1+
- DB user with `CREATE` and `ALTER` privileges (for custom table mode)

## Project Layout

- [enterprise-cpt.php](enterprise-cpt.php): bootstrap, activation checks, autoloading, runtime wiring
- [src/Plugin.php](src/Plugin.php): app composition, hooks, admin menus, script enqueue
- [src/Engine/CPT.php](src/Engine/CPT.php): CPT definition engine (`definitions/cpt/*.json` + option buffer)
- [src/Engine/FieldGroups.php](src/Engine/FieldGroups.php): field group engine (`blocks/fields/*.json` + option buffer)
- [src/Storage](src/Storage): schema, interceptor, hydrator, table manager, shadow sync
- [src/Rest](src/Rest): field group and CPT REST controllers
- [src/Blocks/BlockFactory.php](src/Blocks/BlockFactory.php): registers Gutenberg blocks for field groups with `is_block: true`
- [assets/src](assets/src): React/JS source for editor apps
- [assets/build](assets/build): compiled assets used at runtime

## Admin Apps

- Enterprise CPT (Post Types): `admin.php?page=enterprise-cpt`
  - Bundle: [assets/build/cpt-manager/cpt-manager.js](assets/build/cpt-manager/cpt-manager.js)
- Field Groups: `admin.php?page=enterprise-cpt-field-groups`
  - Bundle: [assets/build/editor/editor.js](assets/build/editor/editor.js)

## REST API

Base namespace: `enterprise-cpt/v1`

### CPT Endpoints

- `GET /cpts`
  - Returns CPT list with source metadata (`json` or `db_buffer`)
- `POST /cpts/save`
  - Saves a CPT definition by slug

### Field Group Endpoints

- `GET /field-groups`
  - Returns list summary: slug, title, locations summary, storage badge source
- `GET /field-groups/{slug}`
  - Returns full field group schema
- `POST /field-groups/save`
  - Saves field group definition
- `DELETE /field-groups/{slug}`
  - Deletes from JSON and/or DB buffer

### Field Type Library

Field settings are registry-driven in [assets/src/editor/fields/registry.js](assets/src/editor/fields/registry.js):

- `text`
- `textarea`
- `number` (supports `min`, `max`, `step`)
- `email`
- `true_false` (supports `on_text`, `off_text`)
- `select` (supports `choices`)
- `radio` (supports `choices`)
- `repeater` (supports subfields and focus-canvas editing)

Repeater subfields currently support:

- `text`, `textarea`, `number`, `email`, `image`
- Image row editing via media picker with inline preview in the post editor

Sidebar editors for these types are under [assets/src/editor/fields](assets/src/editor/fields).

Storage mapping in [src/Storage/Schema.php](src/Storage/Schema.php):

- `text`, `email`, `select`, `radio` → `VARCHAR(255)`
- `textarea` → `TEXT`
- `number` → `BIGINT(20)` or `DECIMAL(20,6)` when decimal step is used
- `true_false` → `TINYINT(1)`
- `image` → `BIGINT(20) UNSIGNED`
- `repeater` parent value → `LONGTEXT` JSON shadow, with relational child table rows in custom table mode (`wp_enterprise_repeater_{field_name}`)

### Shared Endpoints

- `GET /search?type=post_type|taxonomy|user_role&q=<term>&page=1&per_page=20`

All endpoints are capability-gated (`manage_options`).

## Storage Engine Notes

- `TableManager`: creates/alters custom tables from field definitions
- `Interceptor`: redirects post meta operations to custom tables for mapped keys, including repeater child-table synchronization
- `Hydrator`: bulk primes cache for custom table rows, including optimized repeater child-table hydration
- `ShadowSync`: async mirror from custom table to postmeta
- `FieldType` enum centralizes SQL type, defaults, and format placeholders

Frontend rendering notes:

- Repeater image subfields render actual images on the frontend (not attachment IDs)
- Rendering respects repeater subfield image schema when present and falls back to sensible defaults

## Getting Started

### 1. Install PHP Dependencies

```bash
composer install
```

### 2. Install JS Dependencies

```bash
npm install
```

### 3. Build Assets

```bash
npm run build
```

### 4. Activate Plugin

Place this directory at:

`wp-content/plugins/enterprise-cpt`

Then activate **Enterprise CPT** in wp-admin.

## NPM Scripts

- `npm run build`: builds all four bundles (index + editor + cpt-manager + blocks)
- `npm run build:index`: builds editor-side post field panel
- `npm run build:editor`: builds field group manager bundle
- `npm run build:cpt-manager`: builds CPT manager bundle
- `npm run build:blocks`: builds universal field block bundle
- `npm run start:index`, `npm run start:editor`, `npm run start:cpt-manager`: watch mode for each entry

## WP-CLI Commands

Available under `wp enterprise-cpt`:

- `register_definitions`
- `list_definitions`
- `save_cpt <slug> --json='<json-object>'`
- `migrate --group=<slug> [--reverse] [--dry-run]`

Storage-specific commands:

- `wp enterprise-cpt storage status`
- `wp enterprise-cpt storage migrate --group=<slug> [--batch-size=100] [--dry-run]`
- `wp enterprise-cpt storage sync-check --group=<slug>`

## Field-to-Block Bridge

Field groups can be exposed as native Gutenberg blocks by adding `"is_block": true` to their JSON definition.

### How It Works

1. **PHP Registration** — `BlockFactory` (called on `init` priority 8) scans field group definitions for `is_block: true` and calls `register_block_type('enterprise-cpt/{slug}')` for each one.

2. **React Edit Component** — The universal block Edit component detects which field group it represents, shows a summary view on the canvas, and opens a centered modal (Focus Canvas) with all custom fields when "Edit Data" is clicked.

3. **Enterprise Template Resolver** — Rendering now uses a shared resolver for both frontend and editor SSR preview. This solves two recurring issues:
  - Preview/frontend drift (different template lookup logic)
  - Missing-template failures in early development

  Resolution order:
  1. Active theme override: `{theme}/enterprise-cpt/blocks/{slug}.php`
  2. Persistent scaffold storage: `wp-content/uploads/enterprise-cpt/templates/{slug}.php`
  3. Plugin fallback: `templates/generic-block.php`

  Because the fallback always exists, rendering is resilient by default.

4. **Template Scaffolder** — When a field group is saved with `is_block: true`, the plugin attempts to scaffold a starter template into uploads **only if**:
  - no theme template exists
  - no uploads template already exists

  This keeps developer overrides authoritative while still giving non-theme users a writable customization path.

5. **Security Hardening for Upload Templates** — The scaffold directory is protected with:
  - `index.php` (directory listing protection)
  - `.htaccess` deny rules for direct PHP execution

6. **Non-Blocking Failure Signaling** — If scaffold writes fail (permissions/read-only FS), save succeeds and the REST response includes `scaffold_warning`, allowing the admin UI to show: `Template generation skipped - using fallback`.

7. **Storage Resolver** — The `rest_pre_insert_post` filter intercepts post saves, parses any `enterprise-cpt/*` blocks from the post content, and upserts their attributes into the field group's custom table keyed by `block_instance_id`. This means block data never stays only in block attributes — it is persisted to the same custom table as standard field groups.

8. **Block Instance ID** — Each block instance gets a UUID (`blockInstanceId` attribute) on first render, so multiple blocks of the same type on one page each have an isolated row in the custom table.

9. **FSE-Smart Preview Strategy** — The block preview pipeline is context-aware so it behaves well in both Post Editor and Site Editor:
  - **Post Editor**: lower-latency SSR preview updates for fast content iteration
  - **Site Editor (`wp_template`, `wp_template_part`)**: throttled SSR, manual **Refresh Preview** control, and paused fetch while actively editing modal fields

10. **Preview Cache + Graceful Degradation** — SSR responses are cached per `block_slug + attributes` to reduce duplicate requests. If SSR preview fails in Site Editor, the UI falls back to a compact field-summary view instead of triggering a hard block preview crash.

### Why This Feature Exists

The resolver/scaffolder design was introduced to make block rendering production-safe and editor-friendly in real environments:

- **Consistent output pipeline**: one resolver for frontend and SSR preview
- **Zero-config first render**: plugin fallback prevents broken previews
- **Progressive customization**: users can start with scaffolded templates, then promote to theme templates
- **Read-only resilience**: if writes fail, blocks still render and users get explicit warnings

The FSE-smart preview extension was added for editor performance and reliability at scale:

- **Site Editor safety**: templates and template parts often contain many dynamic blocks; throttling and manual refresh reduce preview storming
- **Operational stability**: temporary SSR errors in Site Editor degrade to summary preview instead of hard failure UI
- **Lower server overhead**: request deduplication via preview cache avoids redundant render calls on unchanged data

### Enabling a Block

Add `"is_block": true` to any field group JSON in `blocks/fields/`:

```json
{
  "type": "field_group",
  "name": "hero_banner",
  "title": "Hero Banner",
  "is_block": true,
  "fields": [
    { "type": "text", "name": "heading", "label": "Heading" },
    { "type": "image", "name": "background", "label": "Background Image" }
  ]
}
```

The block will appear in the **Enterprise CPT** block category in the block inserter.

### Template Authoring Contract

Templates receive:

- `$fields` as an associative array of values
- `$group` as the field group definition array

Example theme template:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

$fields = (array) $fields;
?>
<section class="hero-banner">
  <h2><?php echo esc_html( $fields['heading'] ?? '' ); ?></h2>
  <p><?php echo esc_html( $fields['description'] ?? '' ); ?></p>
</section>
```

### Editor Context Behavior (Post Editor vs Site Editor)

- Context detection uses Gutenberg stores (`core/editor` and `core/edit-site`) and post type checks.
- Site Editor behavior applies when editing `wp_template` or `wp_template_part`.
- In Site Editor mode:
  - debounce window is longer
  - live preview pauses while editing modal fields
  - a **Refresh Preview** action is available
  - fallback summary UI is used when SSR preview errors occur

### Known Limitations

- Preview cache is in-memory per browser tab/session and is not shared across users or devices.
- In Site Editor, preview updates are intentionally throttled; use **Refresh Preview** for immediate SSR re-render.
- If render templates depend on external runtime state (custom globals, non-deterministic queries), cached preview HTML may lag until refresh.
- Filesystem read-only environments rely on DB buffer fallback for definitions/templates; writes may be skipped with warnings.

## Development Notes

- Use JSON definitions in [definitions/cpt](definitions/cpt) and [blocks/fields](blocks/fields) for source control portability.
- In read-only environments, saves are buffered to WordPress options and merged at runtime.
- The plugin uses strict types and PHP 8.1 language features.
- If you run WordPress under WSL/Linux, ensure `npm`/`node` resolve to Linux binaries, not Windows UNC context.

## License

GPL-2.0-or-later
