Act as a Senior React and WordPress Developer. We realized we are missing the visual UI for creating and editing Custom Post Types in our `enterprise-cpt` plugin. 

### 1. REST API Extension
- Add new endpoints to the existing REST controller (or create `EnterpriseCPT\Rest\CPTController`):
    - `GET /enterprise-cpt/v1/cpts`: Returns a list of all registered CPTs and their source (JSON vs DB Buffer).
    - `POST /enterprise-cpt/v1/cpts/save`: Accepts a JSON payload from the UI and passes it to the `save_definition` method in our `CPT.php` engine.

### 2. React Frontend: CPT Manager
- In the `assets/src/` directory, create a new app entry for the CPT Manager (e.g., `assets/src/cpt-manager/index.js`).
- **List View Component:** Build a clean data table using `@wordpress/components` to list existing CPTs. Include an "Add New" button.
- **Editor Component:** Build a form for creating/editing a CPT. It must include:
    - `TextControl` for the Slug (must be sanitized to lowercase/dashes).
    - `TextControl` for Singular and Plural Labels.
    - `PanelBody` with `ToggleControl` components for the `supports` array (Title, Editor, Thumbnail, Excerpt).
    - `PanelBody` with `ToggleControl` for Enterprise features (Enable Custom Table Engine for this CPT).
- **State Management:** Use `useState` or `@wordpress/data` to manage the form state before sending it to the REST API.

### 3. PHP Integration
- Update the main menu page (`admin.php?page=enterprise-cpt`) to render a `<div id="enterprise-cpt-manager-root"></div>`.
- Enqueue the new compiled JS file specifically on this screen, localizing the REST URL and Nonce.

Ensure the UI matches the clean, full-screen Gutenberg aesthetic we established for the Field Group editor.