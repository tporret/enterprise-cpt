=== Enterprise CPT ===
Contributors: tporret
Tags: custom post type, custom fields, gutenberg, metadata, performance
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise CPT is a JSON-first plugin for managing Custom Post Types and Field Groups with a modern WordPress admin UI and performance-focused storage options.

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
- Standard field types: Text, Textarea, Number, Email, True/False, Select, Radio
- Type-specific settings (number ranges/step, true/false labels, select/radio choices)

= Performance Storage =

- Optional custom table storage per field group
- Postmeta shadow sync support for compatibility
- Metadata interception + cache layer for reduced query overhead

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

= 0.1.0 =

- CPT Manager admin UI
- Field Group List + Editor view router
- Field Group REST endpoints: list, single, save, delete
- CPT REST endpoints: list and save
- Custom table storage engine with metadata interception
- Activation checks, environment guards, and admin hardening
- Standard Field Type Library (textarea, number, email, true/false, select, radio)

== Upgrade Notice ==

= 0.1.0 =

Initial public development release for enterprise content model management.
