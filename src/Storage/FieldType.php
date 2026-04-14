<?php

declare(strict_types=1);

namespace EnterpriseCPT\Storage;

/**
 * PHP 8.3 backed enum representing the supported field types.
 *
 * Provides the canonical SQL column definition, wpdb format placeholder,
 * and empty default value for every type so no map arrays need to be
 * scattered across multiple classes.
 */
enum FieldType: string
{
    case Text      = 'text';
    case Textarea  = 'textarea';
    case Repeater  = 'repeater';
    case Number    = 'number';
    case Email     = 'email';
    case Select    = 'select';
    case Radio     = 'radio';
    case TrueFalse = 'true_false';
    case Image     = 'image';

    /**
     * Return the enum case for a given string, falling back to Text for unknown values.
     */
    public static function fromString(string $type): self
    {
        return self::tryFrom($type) ?? self::Text;
    }

    /**
     * SQL column definition fragment suitable for use in CREATE TABLE / ALTER TABLE.
     */
    public function columnSql(): string
    {
        return match ($this) {
            self::Text, self::Email, self::Select, self::Radio => "VARCHAR(255) NOT NULL DEFAULT ''",
            self::Textarea  => 'TEXT NOT NULL',
            self::Repeater  => 'LONGTEXT NOT NULL',
            self::Number    => 'BIGINT(20) NOT NULL DEFAULT 0',
            self::TrueFalse => 'TINYINT(1) NOT NULL DEFAULT 0',
            self::Image     => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        };
    }

    /**
     * wpdb format placeholder (%s or %d).
     */
    public function format(): string
    {
        return match ($this) {
            self::Number, self::TrueFalse, self::Image => '%d',
            default => '%s',
        };
    }

    /**
     * The empty/reset value for this type (used when a column is cleared or first written).
     */
    public function default(): mixed
    {
        return match ($this) {
            self::Repeater => '[]',
            self::Number, self::TrueFalse, self::Image => 0,
            default => '',
        };
    }
}
