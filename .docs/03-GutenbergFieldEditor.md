Act as a Senior Full-Stack WordPress Developer. We are building the "Field Group Editor" for the `enterprise-cpt` plugin. This must be a full-screen, Gutenberg-native React application.

### 1. Backend: REST API Infrastructure
- Create a PSR-4 class `EnterpriseCPT\Rest\FieldGroupController`.
- Register a namespace `enterprise-cpt/v1`.
- Implement endpoints:
    - `GET /field-groups`: Fetch all JSON-defined and DB-buffered field groups.
    - `POST /field-groups/save`: Handle saving field group definitions (using the "State-Sync" logic: JSON first, DB fallback).
- Ensure all endpoints use `permission_callback` to check for `manage_options`.

### 2. Frontend: Gutenberg UI Scaffold
- Create an entry point at `assets/src/editor/index.js`.
- Use `@wordpress/components` to create a full-screen layout including:
    - A **HeaderBar**: With "Save", "Export JSON", and "Environment Status" (Read-only vs. Writable).
    - A **Sidebar**: For global Field Group settings (Location Rules, Custom Table Name).
    - A **Main Canvas**: A list-view of fields where users can add, drag-and-drop, and edit field types.
- Implement a basic "Text Field" component as a React functional component using the `TextControl` from WP Core.

### 3. The "Core-Look" Integration
- Use the `add_menu_page` hook to create the "Field Groups" menu item.
- Create a PHP template that initializes the app. Use `wp_enqueue_script` to load the compiled JS and `wp_localize_script` to pass the REST URL, Nonce, and current environment status (is_writable).
- Crucially: Use `remove_all_actions( 'admin_notices' )` on this specific page to ensure a clean, "Full-Screen" enterprise feel without other plugins' clutter.

### 4. Technical Constraints
- No jQuery. Use `@wordpress/element` (React).
- Use `@wordpress/data` for state management.
- The UI must look exactly like the WordPress Site Editor (dark sidebars, clean typography).

Please generate the REST Controller and the PHP wrapper for the Admin UI first.