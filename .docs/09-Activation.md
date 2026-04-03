Act as a Principal Engineer. Finalize the `enterprise-cpt.php` main entry file.

### Requirements:
1. **Directory Setup:** On `register_activation_hook`, ensure the folder `wp-content/uploads/enterprise-cpt/definitions/` exists and has an `index.php` for security.
2. **Autoloader Integration:** Ensure the PSR-4 autoloader (via Composer or a custom logic) is correctly mapping `EnterpriseCPT\` to the `src/` directory.
3. **The Storage Guard:** Add a check to ensure `$wpdb` has `CREATE` and `ALTER` permissions. If not, throw a `wp_die()` with a helpful "Enterprise Environment Error."
4. **The "Shadow" Worker:** Ensure the Action Scheduler or a single WP-Cron event is registered to handle the asynchronous "Shadow Sync" to postmeta.
5. **Admin Menu:** Register the top-level "Enterprise CPT" menu and submenus for "Post Types" and "Field Groups."

Ensure the code is strictly typed and uses PHP 8.1+ features like `readonly` properties for the main plugin instance.