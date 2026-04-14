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
echo \EnterpriseCPT\Templates\Resolver::render_default_block_markup( $group, $fields );
