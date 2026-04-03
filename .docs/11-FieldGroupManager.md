Act as a Senior React and WordPress Developer. We need to implement a "List View" dashboard for our Field Groups in the `enterprise-cpt` plugin, as currently, users are stuck in the single-editor view.

### 1. REST API Refinement
- Ensure the `EnterpriseCPT\Rest\FieldGroupController` has these exact endpoints:
    - `GET /enterprise-cpt/v1/field-groups`: Returns an array of all field groups (slug, title, location rules summary, custom table status).
    - `GET /enterprise-cpt/v1/field-groups/(?P<slug>[a-zA-Z0-9_-]+)`: Returns the full JSON schema for a single group.
    - `DELETE /enterprise-cpt/v1/field-groups/(?P<slug>[a-zA-Z0-9_-]+)`: Deletes the JSON file/DB buffer for a group.

### 2. React Frontend: The Manager & Router
- Modify the `assets/src/editor/index.js` (or your root Field Group React file) to act as a state router.
- **State Management:** Implement a state variable `currentView` (values: `'list'` or `'editor'`) and `activeGroupSlug` (values: `string` or `null`).
- **The List View Component (`FieldGroupList.js`):**
    - Build a high-quality data table using `@wordpress/components` to display all field groups.
    - Columns should include: Title, Name (Slug), Locations, and Storage (Custom Table vs Postmeta badge).
    - Include an "Add New" button at the top that sets `currentView` to `'editor'` and `activeGroupSlug` to `null` (blank canvas).
    - Add row actions: "Edit" (sets `activeGroupSlug` and switches view) and "Delete" (triggers DELETE REST API and refreshes list).
- **The Editor View Component (Integration):**
    - Wrap the existing full-screen Gutenberg editor so it accepts `activeGroupSlug` as a prop.
    - Add a "Back to Field Groups" button in the HeaderBar of the editor to return to the List View.

### 3. Polish & UX
- Ensure loading states (`Spinner` from `@wordpress/components`) are used while fetching the list or saving a group.
- Implement a `Snackbar` (from `@wordpress/components`) to show success/error messages when a field group is saved or deleted.

Please generate the React Router logic and the List View component first, ensuring strict adherence to the `@wordpress/components` library so it feels like native WordPress.