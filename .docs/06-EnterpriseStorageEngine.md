Act as a Principal Data Engineer. We are finalizing the "Custom Table Engine" for `enterprise-cpt`. This engine must be "Strict" for performance but include "Shadow Syncing" for compatibility.

### 1. The Storage Engine (Strict Mode)
- **Namespace:** `EnterpriseCPT\Storage`
- **Logic:** Create a `TableManager.php` that handles `CREATE TABLE` and `ALTER TABLE` based on JSON field definitions.
- **Data Interceptor:**
    - Filter `get_post_metadata`: If a custom table exists for the requested field, intercept and return data from the custom table.
    - Filter `update_post_metadata`: Write data directly to the custom table.

### 2. Asynchronous Shadow Sync (The "Cater to Both" Logic)
- Implement a background task (using `wp_schedule_single_event`) that triggers after a post is saved.
- **Task:** Mirror the data from the Custom Table into `wp_postmeta`. 
- **Requirement:** This must NOT block the main thread. The user should never wait for the shadow sync to finish.

### 3. Migration CLI Tools
- Create a WP-CLI class `EnterpriseCPT\CLI\StorageCommands`.
- **Command 1: `status`** - Lists all field groups and shows if they are using Custom Tables or Postmeta, including row counts.
- **Command 2: `migrate`** - A robust tool to move data from Postmeta to Custom Tables (with batch processing to avoid memory timeouts).
- **Command 3: `sync-check`** - Compares the Custom Table data vs. Postmeta and reports any drift.

### 4. Admin UI "Performance Insights"
- Create a PHP class `EnterpriseCPT\Admin\Insights` that calculates the "Query Savings" (N rows saved per post) to be displayed in the Gutenberg Field Editor sidebar.

### Technical Constraints:
- Use `$wpdb->prefix` for all table names to ensure multisite compatibility.
- Ensure the Custom Table has a `post_id` column indexed as a PRIMARY KEY or UNIQUE.
- Use PHP 8.1+ features (Readonly properties, Enums for field types).

Please generate the `TableManager.php` and the WP-CLI command structure first.