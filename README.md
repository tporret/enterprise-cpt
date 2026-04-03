# Enterprise CPT

Enterprise CPT is a JSON-first WordPress plugin for enterprise content modeling.

This repository is developer-focused and documents architecture, APIs, and local build workflows.

## What It Provides

- CPT Manager UI (React + WP components)
- Field Group Manager UI with List View + Editor View
- Standard Field Type Library (Text, Textarea, Number, Email, True/False, Select, Radio)
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

Sidebar editors for these types are under [assets/src/editor/fields](assets/src/editor/fields).

Storage mapping in [src/Storage/Schema.php](src/Storage/Schema.php):

- `text`, `email`, `select`, `radio` → `VARCHAR(255)`
- `textarea` → `TEXT`
- `number` → `BIGINT(20)` or `DECIMAL(20,6)` when decimal step is used
- `true_false` → `TINYINT(1)`

### Shared Endpoints

- `GET /search?type=post_type|taxonomy|user_role&q=<term>&page=1&per_page=20`

All endpoints are capability-gated (`manage_options`).

## Storage Engine Notes

- `TableManager`: creates/alters custom tables from field definitions
- `Interceptor`: redirects post meta operations to custom tables for mapped keys
- `Hydrator`: bulk primes cache for custom table rows
- `ShadowSync`: async mirror from custom table to postmeta
- `FieldType` enum centralizes SQL type, defaults, and format placeholders

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

- `npm run build`: builds index + field-group editor + CPT manager bundles
- `npm run build:index`: builds editor-side post field panel
- `npm run build:editor`: builds field group manager bundle
- `npm run build:cpt-manager`: builds CPT manager bundle
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

## Development Notes

- Use JSON definitions in [definitions/cpt](definitions/cpt) and [blocks/fields](blocks/fields) for source control portability.
- In read-only environments, saves are buffered to WordPress options and merged at runtime.
- The plugin uses strict types and PHP 8.1 language features.
- If you run WordPress under WSL/Linux, ensure `npm`/`node` resolve to Linux binaries, not Windows UNC context.

## License

GPL-2.0-or-later
