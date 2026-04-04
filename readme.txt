=== Enterprise CPT ===
Contributors: tporret
Tags: custom post type, custom fields, gutenberg, metadata, performance
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise CPT is a JSON-first plugin for managing Custom Post Types and Field Groups with a modern WordPress admin UI, performance-focused storage options, and a native Gutenberg block bridge.

== Description ==

Enterprise CPT gives site owners and teams a clean admin workflow for building content models while keeping definitions portable and versionable.

You can:

- Create and edit Custom Post Types from wp-admin
- Manage Field Groups in a native Gutenberg-style List + Editor interface
- Use custom SQL table storage for field groups (with postmeta shadow compatibility)
- Keep definitions in JSON with read-only environment fallback to DB buffer
- Save and edit safely in containerized environments

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
= Repeater Focus Canvas =

- List view with drag-and-drop row reordering (HTML5 DnD)
- Per-row Edit (pencil), Clone (copy), and Delete (trash) icon actions
- Row summary shows the first subfield value
- Centered modal titled "Editing [Row Name]" with Done / Discard Changes footer
- Clone action triggers a "Row cloned" snackbar notification
- Bulk image row creation from the media library
- Inline image preview, replace, and remove controls

= Field-to-Block Bridge =

- Any field group can become a native Gutenberg block by adding `"is_block": true` to its JSON
- Blocks appear in the **Enterprise CPT** block category in the inserter
- Universal Edit component shows a canvas summary view and opens a Focus Canvas modal for editing
- Server-side rendering via `render_callback` with theme template support: `{theme}/enterprise-cpts/blocks/{slug}.php`
- Template receives a `$fields` stdClass object (e.g. `echo $fields->heading;`)
- Block data is saved to the field group's custom table via `rest_pre_insert_post`, keyed by `block_instance_id`
- Multiple blocks of the same type on one page each get an isolated row in the custom table

= Performance Storage =

- Optional custom table storage per field group
- Relational repeater child-table storage in custom table mode (`wp_enterprise_repeater_{field_name}`)
- Postmeta shadow sync support for compatibility
- Metadata interception + cache layer for reduced query overhead

= Frontend Output =

- Repeater image fields render as actual images on frontend output (not raw attachment IDs)
- Repeater rows continue to render as structured lists for content clarity

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

- PHP 8.1+ requirement check
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

Use **Enterprise CPT > Field Groups** and set the group location to your post type.

= Can I use normal postmeta instead of custom tables? =

Yes. Leave the field group's custom table name empty and the plugin will use postmeta.

= What happens in read-only environments? =

Definition saves are buffered into WordPress options when files are not writable.

= Will this break existing WordPress behavior? =

No. Shadow sync and compatibility paths are included to support standard WordPress metadata access patterns.

== Changelog ==

= 0.2.0 =

- Repeater Focus Canvas UI overhaul: drag-and-drop sorting, icon action buttons (edit/clone/delete), centered modal with Done/Discard footer, clone snackbar
- Field-to-Block Bridge: `is_block: true` on any field group registers a Gutenberg block
- Universal block Edit component with Focus Canvas modal and canvas summary view
- `BlockFactory` PHP class registers blocks dynamically from field group definitions
- Theme template support for block render callbacks (`enterprise-cpts/blocks/{slug}.php`)
- Block storage resolver: syncs block attributes to custom table via `rest_pre_insert_post`
- Block instance isolation via UUID `block_instance_id` per placed block
- New `build:blocks` npm script for the blocks bundle

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
