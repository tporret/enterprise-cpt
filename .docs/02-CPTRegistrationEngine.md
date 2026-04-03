Act as a Senior WordPress Engineer. We are implementing the **CPT Registration Engine** for the `enterprise-cpt` plugin. This engine must be "Container-Aware" and use a "JSON-First" approach.

### 1. Objective
Create a PSR-4 compliant class `EnterpriseCPT\Engine\CPT` that handles the registration of Custom Post Types using JSON definitions stored in the filesystem, with a database fallback for read-only environments (Docker/K8s).

### 2. File Structure & Logic
- **Storage Path:** `/definitions/cpt/`
- **Engine Logic:**
    - On `init` (priority 5), scan `/definitions/cpt/` for `.json` files.
    - Check for a "Database Buffer" in `wp_options` named `enterprise_cpt_buffer`.
    - **Merge Strategy:** The engine must merge JSON definitions and the DB buffer. If a slug exists in both, the DB buffer takes priority (allowing for "hotfixes" in production).
    - Call `register_post_type()` for each definition.
- **State-Sync Method:** - Create a method `save_definition(string $slug, array $data)`.
    - If `is_writable(storage_path)`, save to the JSON file.
    - If NOT writable, save to the `enterprise_cpt_buffer` option and trigger a system log warning.

### 3. Technical Constraints
- **Strict Typing:** Use PHP 8.0+ types (string, array, void, etc.).
- **Enterprise Defaults:** Every CPT registered must have `'show_in_rest' => true` and `'delete_with_user' => false` by default unless explicitly overridden in the JSON.
- **Performance:** Use `glob()` for file scanning and ensure results are cached in a class variable to prevent redundant disk I/O during a single request.
- **UI Check:** Add a helper method `is_readonly_env()` that returns a boolean based on directory permissions, which we will later use to toggle the admin UI.

### 4. Boilerplate Example
Include a sample `products.json` file in the `/definitions/cpt/` folder to demonstrate the schema, including standard labels and arguments.

Please generate the `CPT.php` engine class and the initialization logic in the main plugin file.