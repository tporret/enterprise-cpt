=== Enterprise CPT ===
Contributors: tporret
Donate link: https://porretto.com/donate/
Tags: custom post type, custom fields, gutenberg, metadata, performance
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise CPT is a JSON-first plugin for managing Custom Post Types and Field Groups with a modern WordPress admin UI, performance-focused storage options, and a native Gutenberg block bridge.

== Description ==

Enterprise CPT gives site owners and teams a clean workflow for building custom content models while keeping schemas portable and versionable.

You can use it to:

- create and edit Custom Post Types from wp-admin
- manage field groups in a modern list-and-editor interface
- assign field groups to multiple post types, taxonomies, or user roles
- store field data in post meta or custom SQL tables
- expose field groups as native Gutenberg blocks
- keep JSON-backed definitions working in read-only environments through buffered fallbacks

== Features ==

= Custom Post Type Manager =

- Admin screen at `Enterprise CPT > Post Types`
- Slug-aware form with automatic label suggestions
- Supports toggles (Title, Editor, Thumbnail, Excerpt)
- Save directly to JSON (or DB buffer when file system is read-only)

= Field Group Manager =

- List view dashboard with Edit/Delete actions and location/storage columns
- Editor view with back navigation and field-focused sidebar settings
- Location summary and storage badge in list table
- Save and export field group schemas
- Standard field types: Text, Textarea, Number, Email, True/False, Select, Radio, Repeater
- Type-specific settings (number ranges/step, true/false labels, select/radio choices)

= Repeater Editing =

- List view with drag-and-drop row reordering (HTML5 DnD)
- Per-row Edit (pencil), Clone (copy), and Delete (trash) icon actions
- Row summary shows the first subfield value
- Centered modal titled "Editing [Row Name]" with Done / Discard Changes footer
- Clone action triggers a "Row cloned" snackbar notification
- Bulk image row creation from the media library
- Inline image preview, replace, and remove controls


= Location Rules =

- Field groups can appear on multiple post types, taxonomies, or user roles simultaneously
- Checkbox-based multi-select UI for selecting multiple location targets
- OR logic: field group shows when context matches ANY selected value
- Supports the canonical `locations` model and legacy single-value rules


= Block Bridge =

- Any field group can become a native Gutenberg block by adding `"is_block": true` to its JSON
- Blocks appear in the **Enterprise CPT** block category in the inserter
- Universal Edit component shows a canvas summary view and opens a Focus Canvas modal for editing
- Live preview in the editor is server-rendered and uses the same template resolution path as frontend output
- Enterprise Template Resolver checks templates in this order:
	1. Active Theme: `{theme}/enterprise-cpt/blocks/{slug}.php`
	2. Persistent Storage: `wp-content/uploads/enterprise-cpt/templates/{slug}.php`
	3. Plugin fallback: `templates/generic-block.php`
- If no custom template exists, the plugin fallback renders clean semantic HTML automatically
- When a field group is saved with `"is_block": true`, a starter template is auto-generated in uploads when needed
- Upload template folder is hardened with `index.php` and `.htaccess`
- Block data is saved to the field group's custom table via `rest_pre_insert_post`, keyed by `block_instance_id`
- Multiple blocks of the same type on one page each get an isolated row in the custom table
- Repeater rows render as structured content instead of raw arrays
- Repeater image subfields render real image tags on the frontend

= Block Templates: How To Use =

1. Create or edit a Field Group and enable block mode (`"is_block": true`).
2. Insert the block in Gutenberg from the **Enterprise CPT** category.
3. Customize output by creating a theme template at:
	 - `wp-content/themes/your-theme/enterprise-cpt/blocks/{slug}.php`
4. If no theme template exists, Enterprise CPT uses uploads scaffold (if available), then plugin fallback.

Template variables:

- `$fields` (associative array of values)
- `$group` (field group definition)

Example:

`<h2><?php echo esc_html( $fields['heading'] ?? '' ); ?></h2>`

= Multi-Select Location Rules =

Field groups can now appear on multiple post types, taxonomies, or user roles using multi-select checkboxes:

- Edit a field group and go to **Location Rules**
- Choose a rule type (Post Type, Taxonomy, or User Role)
- Check the boxes for all locations where this field group should appear
- The field group will appear on **any** of the selected locations (OR logic)

Example: A "Product Details" field group can appear on Post, Product, and Service post types simultaneously.


= Storage =

- Optional custom table storage per field group
- Relational repeater child-table storage in custom table mode (`wp_enterprise_repeater_{field_name}`)
- Postmeta shadow sync support for compatibility
- Metadata interception + cache layer for reduced query overhead

= Frontend Output =

- Shared rendering path for editor previews and frontend block output
- Repeater image fields render as actual images on frontend output
- Repeater rows render as structured content for clearer output

= REST API =

- Field groups listing, single fetch, save, and delete
- CPT listing and save endpoints
- Search endpoint for location targets

Current field group endpoints:

- `GET /field-groups`
- `GET /field-groups/<slug>`
- `POST /field-groups/save`
- `DELETE /field-groups/<slug>`

= Safety + Environment Checks =

- PHP 8.3+ requirement check
- CREATE/ALTER database permission checks
- Activation-time folder hardening for upload definitions directory

== Installation ==

1. Copy the plugin to `wp-content/plugins/enterprise-cpt`.
2. Activate **Enterprise CPT** from the WordPress Plugins screen.
3. Go to **Enterprise CPT** in wp-admin.
4. Create a Post Type, then create Field Groups for that post type.

== Frequently Asked Questions ==

= Where do I add new post types? =

In wp-admin, open **Enterprise CPT > Post Types**.

= Where do I assign custom fields to my post types? =

Use **Enterprise CPT > Field Groups** and set the group location under **Location Rules**.

= Can I use normal postmeta instead of custom tables? =

Yes. Leave the field group's custom table name empty and the plugin will use postmeta.

= What happens in read-only environments? =

Definition saves are buffered into WordPress options when files are not writable.

= Will this break existing WordPress behavior? =

No. Shadow sync and compatibility paths are included to support standard WordPress metadata access patterns.

= Can one field group be reused in more than one place? =

Yes. A single field group can target multiple post types, taxonomies, or user roles at the same time.

= How do block templates work? =

Create a theme template at `wp-content/themes/your-theme/enterprise-cpt/blocks/{slug}.php`. If no theme template exists, the plugin can use an uploads scaffold or the built-in fallback renderer.

== Changelog ==

= 0.5.1 =

- Refactored admin/editor inline layout styles into shared WPDS-friendly stylesheet classes
- Added shared `assets/src/common/admin-style.css` for editor and CPT manager bundles
- Validated full build success across all JavaScript asset bundles with `npm run build`
- Confirmed no current reliance on WP Interactivity API or WP Abilities API in plugin source

= 0.5.0 =

- Block rendering cleanup: shared schema-aware renderer now handles repeater rows and repeater image subfields consistently on the frontend
- Generated uploads block templates now route through the same default renderer as the plugin fallback
- Multi-select location rules: field groups can now appear on multiple post types, taxonomies, or user roles simultaneously
- Checkbox-based UI for selecting multiple location targets with OR logic
- New REST endpoint `/location-options` returns available post types, taxonomies, and user roles
- Updated rule evaluation classes (PostTypeRule, TaxonomyRule, UserRoleRule) to support array values
- Drag-and-drop field reordering within field group editor with auto-save
- Field reordering UI with visual feedback (drag handles, selected state, dragging opacity)
- Auto-save field order to REST API when fields are reordered

= 0.4.0 =

- FSE-ready smart preview mode for Enterprise CPT blocks in Site Editor (templates/template parts/patterns)
- Context-aware preview strategy: fast SSR in Post Editor, throttled SSR + manual refresh in Site Editor
- Automatic preview pause while editing in Focus Canvas modal to reduce SSR churn
- In-memory preview cache keyed by block+attributes for fewer duplicate render requests
- Graceful Site Editor fallback summary when SSR preview errors occur (no hard block crash)
- Save-time block slug normalization messaging in Field Group editor responses (`block_slug_warning`)

= 0.2.0 =

- Repeater Focus Canvas UI overhaul: drag-and-drop sorting, icon action buttons (edit/clone/delete), centered modal with Done/Discard footer, clone snackbar
- Field-to-Block Bridge: `is_block: true` on any field group registers a Gutenberg block
- Universal block Edit component with Focus Canvas modal and canvas summary view
- `BlockFactory` PHP class registers blocks dynamically from field group definitions
- Theme template support for block render callbacks (`enterprise-cpts/blocks/{slug}.php`)
- Block storage resolver: syncs block attributes to custom table via `rest_pre_insert_post`
- Block instance isolation via UUID `block_instance_id` per placed block
- New `build:blocks` npm script for the blocks bundle

= 0.3.0 =

- Enterprise Template Resolver introduced for block rendering consistency across editor preview and frontend
- Template lookup order: active theme (`enterprise-cpt/blocks`), uploads scaffold storage, plugin generic fallback
- New generic fallback template (`templates/generic-block.php`) for safe default rendering
- New template scaffolder auto-generates starter templates to uploads on field group save when `is_block: true`
- Uploads template directory hardening via `index.php` and `.htaccess`
- Field Group save REST response now includes non-blocking scaffold warnings when file writes are unavailable

= 0.1.0 =

- CPT Manager admin UI
- Field Group List + Editor view router
- Field Group REST endpoints: list, single, save, delete
- CPT REST endpoints: list and save
- Custom table storage engine with metadata interception
- Activation checks, environment guards, and admin hardening
- Standard Field Type Library (textarea, number, email, true/false, select, radio)
- Repeater Focus Canvas editor and repeater media bulk add workflow
- Repeater frontend image rendering support

== Upgrade Notice ==

= 0.1.0 =

Initial public development release for enterprise content model management.
