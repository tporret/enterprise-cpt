Act as a Lead Database Engineer and WordPress Architect. We are building the "Custom Table Mapper" for `enterprise-cpt`. This is a high-performance alternative to wp_postmeta.

### 1. Objective
Create a "Storage Resolver" system that allows Field Groups to store their data in dedicated flat SQL tables instead of the standard metadata table.

### 2. Core Architecture (PHP)
- **Namespace:** `EnterpriseCPT\Storage`
- **Schema Manager (`Schema.php`):**
    - Method `create_table_from_group(array $group_definition)`: Converts a Field Group's fields into SQL columns.
    - Map field types: `text` => `VARCHAR(255)`, `textarea` => `TEXT`, `repeater` => `LONGTEXT` (JSON), `number` => `BIGINT`.
    - Always include `post_id` as a PRIMARY KEY or UNIQUE INDEX to ensure 1-to-1 mapping.
- **Data Interceptor (`Interceptor.php`):**
    - Use the `get_post_metadata` and `update_post_metadata` filters.
    - Logic: If the requested meta key belongs to a Field Group mapped to a custom table, ABORT the standard WordPress DB call and execute a custom SQL `SELECT` or `INSERT/UPDATE` on our custom table instead.
- **The "Hydrator" (`Hydrator.php`):**
    - A utility to bulk-load all custom table data for a post in a single query and prime the internal WP Meta Cache to prevent "Lazy Loading" overhead.

### 3. WP-CLI Integration
- Create a command `wp enterprise-cpt migrate --group=<slug>`:
    - This must loop through existing `wp_postmeta` data for a specific group and "hoist" it into the new custom SQL table.
    - Provide a `--reverse` flag to move data back to postmeta if needed.

### 4. Technical Constraints
- Use `$wpdb->prepare` for every single query. No exceptions.
- Implement a caching layer: If data is fetched from the custom table, store it in `wp_cache` to avoid redundant SQL hits.
- Handle "Partial Saves": If a post is saved and only 2 of 10 fields are updated, the SQL `UPDATE` should only target those columns or handle the row gracefully.

Please generate the `Schema.php` generator and the `Interceptor.php` logic first.