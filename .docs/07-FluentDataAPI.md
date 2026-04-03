Act as a Senior Developer. Create a public-facing API class `EnterpriseCPT\API\Field`.

### Requirements:
- **Method `get(string $key, int $post_id = null)`**: 
    - Automatically detects if the field is in a Custom Table or Postmeta.
    - Implements **Object Caching (WP_Cache)** so calling the same field multiple times in one page load only hits the DB once.
- **Method `get_group(string $group_slug, int $post_id = null)`**:
    - Fetches the ENTIRE row from a custom table in one single SELECT query and returns it as a keyed object.
- **Type Casting**: Ensure the API returns proper types (e.g., a "Number" field returns an `int/float`, not a `string`).

Generate this class and a global helper function `ecpt_get_field()` for ease of use.