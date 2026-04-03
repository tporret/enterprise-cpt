Act as a Senior React and WordPress Developer. Now that the `enterprise-cpt` Field Group Editor and Custom Table engine are fully connected via the Text field boilerplate, we need to implement the standard field types.

### 1. React Frontend (Field UI Components)
In the `assets/src/editor/fields/` directory, create the following field type components using `@wordpress/components`:
- **Textarea:** Use `TextareaControl`.
- **Number:** Use `__experimentalNumberControl` (or standard `TextControl` with type="number"). Add settings for `min`, `max`, and `step`.
- **Email:** Use `TextControl` with type="email".
- **True/False (Boolean):** Use `ToggleControl`. Include settings for "On Text" and "Off Text".
- **Select / Dropdown:** Use `SelectControl`. Add a setting area in the sidebar for users to input the "Choices" (format: value : label, one per line).
- **Radio Buttons:** Use `RadioControl`. Share the same "Choices" setting logic as the Select field.

### 2. The Field Registry Integration
- Update the main Field Adder component so that the "Field Type" dropdown now includes all these new options: Text, Textarea, Number, Email, True/False, Select, and Radio.
- Ensure the state dynamically renders the correct setting fields in the inspector sidebar based on the selected type.

### 3. PHP Backend (Schema Mapper)
Update the `EnterpriseCPT\Storage\Schema` class (specifically the `create_table_from_group` method) to map these new types to the correct SQL data types:
- `text`, `email`, `select`, `radio` -> `VARCHAR(255)`
- `textarea` -> `TEXT`
- `number` -> `BIGINT` (or `DECIMAL` if step is decimal)
- `true_false` -> `TINYINT(1)`

Please generate the React components for these fields and the updated PHP SQL Schema mapping logic.