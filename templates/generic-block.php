<?php
/**
 * Generic block fallback template.
 *
 * This template is used when no theme or scaffolded template exists for a block.
 * It loops through all non-empty fields and renders them in semantic HTML.
 *
 * Available variables:
 *   $fields — associative array of field values.
 *   $group  — the full field group definition array.
 *
 * @package EnterpriseCPT
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$fields = (array) $fields;
$group  = (array) ( $group ?? [] );
$title  = esc_html( (string) ( $group['title'] ?? $group['name'] ?? 'Field Group' ) );
$slug   = esc_attr( sanitize_key( (string) ( $group['name'] ?? '' ) ) );

$field_defs = is_array( $group['fields'] ?? null ) ? $group['fields'] : [];

// Collect non-empty field output items.
$items = [];

foreach ( $field_defs as $field_def ) {
    if ( ! is_array( $field_def ) ) {
        continue;
    }

    $name  = (string) ( $field_def['name'] ?? '' );
    $label = (string) ( $field_def['label'] ?? $name );
    $type  = (string) ( $field_def['type'] ?? 'text' );
    $value = $fields[ $name ] ?? '';

    // Skip empty values.
    if ( $value === '' || $value === null ) {
        continue;
    }

    // Skip internal meta fields.
    if ( str_starts_with( $name, '_' ) ) {
        continue;
    }

    $items[] = [
        'label' => $label,
        'type'  => $type,
        'value' => $value,
    ];
}

if ( $items === [] ) {
    return;
}
?>
<div class="enterprise-cpt-block enterprise-cpt-block--<?php echo $slug; ?>">
    <h2 class="enterprise-cpt-block__title"><?php echo $title; ?></h2>

    <div class="enterprise-cpt-block__fields">
        <?php foreach ( $items as $item ) : ?>
            <div class="enterprise-cpt-block__field enterprise-cpt-block__field--<?php echo esc_attr( $item['type'] ); ?>">
                <span class="enterprise-cpt-block__label"><?php echo esc_html( $item['label'] ); ?>:</span>
                <?php
                switch ( $item['type'] ) {
                    case 'image':
                        $attachment_id = (int) $item['value'];
                        if ( $attachment_id > 0 ) {
                            $img = wp_get_attachment_image( $attachment_id, 'medium' );
                            if ( $img ) {
                                echo '<span class="enterprise-cpt-block__value">' . $img . '</span>';
                            } else {
                                echo '<span class="enterprise-cpt-block__value">Image #' . $attachment_id . '</span>';
                            }
                        }
                        break;

                    case 'true_false':
                        $bool_label = ! empty( $item['value'] ) ? 'Yes' : 'No';
                        echo '<span class="enterprise-cpt-block__value">' . esc_html( $bool_label ) . '</span>';
                        break;

                    case 'textarea':
                        echo '<div class="enterprise-cpt-block__value">' . wp_kses_post( wpautop( (string) $item['value'] ) ) . '</div>';
                        break;

                    case 'repeater':
                        if ( is_array( $item['value'] ) && $item['value'] !== [] ) {
                            echo '<div class="enterprise-cpt-block__value"><pre>' . esc_html( (string) wp_json_encode( $item['value'], JSON_PRETTY_PRINT ) ) . '</pre></div>';
                        }
                        break;

                    default:
                        echo '<span class="enterprise-cpt-block__value">' . esc_html( (string) $item['value'] ) . '</span>';
                        break;
                }
                ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
