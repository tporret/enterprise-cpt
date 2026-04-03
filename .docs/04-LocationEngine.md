Act as a Senior WordPress Architect. We are building the **Location Rules Engine** for `enterprise-cpt`. This engine decides when and where Field Groups appear.

### 1. Objective
Create a "Compiled" Location Engine that avoids expensive runtime loops. Instead of evaluating rules on every page load, we will "compile" them into a lookup map whenever a Field Group is saved.

### 2. Core Architecture (PHP)
- **Namespace:** `EnterpriseCPT\Location`
- **Rule Interface:** Create `RuleInterface.php` with `match(array $context): bool`.
- **The Compiler (`Compiler.php`):**
    - Method `compile()`: Scans all Field Group JSON files.
    - Generates a `location-registry.json` (or uses the DB Buffer if read-only).
    - The registry should be a flat map like: `['post_type' => ['product' => ['group_id_1', 'group_id_5']]]`.
- **The Evaluator (`Evaluator.php`):**
    - A high-performance class that loads the `location-registry.json` once per request.
    - Provides a method `get_groups_for_context(array $current_screen)` that returns an array of Field Group IDs in O(1) time.

### 3. AJAX Search Infrastructure
- Create a REST API endpoint `GET /enterprise-cpt/v1/search` to handle Location Rule lookups.
- It must support `post_type`, `taxonomy`, and `user_role` searching.
- **Enterprise Requirement:** The search must be debounced and paginated (limit 20) to handle sites with thousands of entries without crashing the UI.

### 4. Gutenberg UI Integration
- In the React Field Group Editor, create a `LocationRules` component.
- Use a "Group" logic: (Group A AND Group B) OR (Group C).
- Use `@wordpress/components` (SelectControl, QueryControls) to allow users to build these rules.
- The UI should pull its options from the AJAX search endpoint created above.

### 5. Technical Constraints
- No hardcoded rules. Use a Factory pattern so we can add `CustomRule` classes later.
- Ensure the `location-registry.json` is automatically regenerated on the `wp_after_insert_post` (for field group post types) or when the JSON definition is updated.
- Use strict PHP 8.0 typing.

Please generate the `RuleInterface.php`, the `Compiler.php` logic, and the REST Search endpoint first.