Act as a Senior WordPress Architect and Data Engineer. We are building "enterprise-cpt", a high-performance, bloat-free alternative to ACF and CPT UI, designed specifically for enterprise-scale data.

### Project Core Principles:
1. **Performance First:** Strictly no jQuery in the admin UI. Use native Gutenberg/React components.
2. **Data Integrity:** Implement a "JSON-First" configuration model. Field groups and CPT definitions must be stored in `/blocks/fields/*.json` and synced to the DB only when necessary.
3. **Scale:** Architect the metadata engine to support "Custom Table Mapping" (moving meta from wp_postmeta to dedicated SQL tables).
4. **Modern PHP:** Use PHP 8.0+ syntax, PSR-4 namespacing, and a strict Object-Oriented approach.

### Technical Requirements for Initial Scaffold:
- **Namespace:** `EnterpriseCPT`
- **Plugin Entry:** `enterprise-cpt.php` with a main controller class.
- **Autoloader:** Setup a standard PSR-4 autoloader.
- **The "Repeater" Engine:** Create a schema for a Repeater Field that stores data as a single optimized JSON blob in postmeta by default, but is structured to be easily "unpacked" into a custom table later.
- **CLI Ready:** Ensure all CPT and Field registrations are hookable via WP-CLI.

### Initial Task:
1. Generate the folder structure:
   - `/src` (Core logic, Field Types, Data Engines)
   - `/assets` (React/Gutenberg sources)
   - `/inc` (Third-party integrations)
   - `/templates` (Admin views)
2. Create the main Plugin Class that initializes the JSON-to-PHP registration engine for Custom Post Types.
3. Create a boilerplate for a 'Text' field type that uses the WordPress `@wordpress/components` library for the admin UI.

Do not use any legacy `add_meta_box` functions unless necessary for backward compatibility; prioritize the Gutenberg 'PluginDocumentSettingPanel' or 'PluginSidebar' API for the field UI.