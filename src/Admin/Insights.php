<?php

declare(strict_types=1);

namespace EnterpriseCPT\Admin;

use EnterpriseCPT\Plugin;

/**
 * Calculates "Performance Insights" (query savings) for the Gutenberg field
 * editor sidebar.
 *
 * The core metric is "estimated postmeta rows saved per post": for each field
 * group backed by a custom table, moving N fields from postmeta to a single
 * row saves (N − 1) postmeta reads per post.
 */
final class Insights
{
    public function __construct(
        private readonly Plugin $plugin
    ) {}

    /**
     * Return the full insights summary to be injected into the localized
     * script data for the Gutenberg field editor.
     *
     * @return array{
     *   totalGroups: int,
     *   customTableGroups: int,
     *   postmetaGroups: int,
     *   estimatedRowsSavedPerPost: int,
     *   breakdown: list<array{group: string, mode: string, fieldCount: int, rowsSavedPerPost: int}>
     * }
     */
    public function summary(): array
    {
        $definitions      = $this->plugin->fieldGroupDefinitions();
        $customTableCount = 0;
        $postmetaCount    = 0;
        $totalRowsSaved   = 0;
        $breakdown        = [];

        foreach ($definitions as $definition) {
            $name       = (string) ($definition['name'] ?? '');
            $fields     = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
            $fieldCount = count($fields);
            $hasTable   = ($definition['custom_table_name'] ?? '') !== '';

            if ($hasTable) {
                // Each field group with a custom table consolidates N postmeta rows
                // into 1 table row — saving N−1 postmeta reads per post load.
                $rowsSaved = max(0, $fieldCount - 1);
                $customTableCount++;
                $totalRowsSaved += $rowsSaved;
            } else {
                $rowsSaved = 0;
                $postmetaCount++;
            }

            $breakdown[] = [
                'group'            => $name,
                'mode'             => $hasTable ? 'custom_table' : 'postmeta',
                'fieldCount'       => $fieldCount,
                'rowsSavedPerPost' => $rowsSaved,
            ];
        }

        return [
            'totalGroups'               => count($definitions),
            'customTableGroups'         => $customTableCount,
            'postmetaGroups'            => $postmetaCount,
            'estimatedRowsSavedPerPost' => $totalRowsSaved,
            'breakdown'                 => $breakdown,
        ];
    }

    /**
     * Return a plain-language headline suitable for a sidebar notice.
     *
     * Example: "Saving ~7 postmeta reads per post (2 groups on custom tables)"
     */
    public function headline(): string
    {
        $summary = $this->summary();

        if ($summary['customTableGroups'] === 0) {
            return 'No custom tables active — all data stored in postmeta.';
        }

        return sprintf(
            'Saving ~%d postmeta read(s) per post (%d group(s) on custom tables).',
            $summary['estimatedRowsSavedPerPost'],
            $summary['customTableGroups']
        );
    }
}
