<?php
/**
 * Shared server-side table sorting helpers.
 * Default direction is DESC (latest records first) unless overridden.
 */

/**
 * Resolve sort key + direction from the request against an allow-list.
 *
 * @param array<string, string> $allowedColumns Map of request key => SQL expression
 * @return array{key: string, dir: string, column: string, orderSql: string}
 */
function resolveTableSort(array $allowedColumns, string $defaultKey, string $defaultDir = 'DESC'): array
{
    $defaultDir = strtoupper($defaultDir) === 'ASC' ? 'ASC' : 'DESC';

    if ($defaultKey === '' || !isset($allowedColumns[$defaultKey])) {
        $defaultKey = (string) array_key_first($allowedColumns);
    }

    $key = (string) get('sort', $defaultKey);
    if (!isset($allowedColumns[$key])) {
        $key = $defaultKey;
    }

    $dir = strtoupper((string) get('dir', $defaultDir));
    $dir = in_array($dir, ['ASC', 'DESC'], true) ? $dir : $defaultDir;

    $column = $allowedColumns[$key];

    return [
        'key' => $key,
        'dir' => $dir,
        'column' => $column,
        'orderSql' => $column . ' ' . $dir,
    ];
}

/**
 * Build a sort toggle URL for a column header.
 * Clicking the active column flips ASC/DESC; other columns start DESC (latest-first).
 */
function tableSortUrl(string $column, string $currentKey, string $currentDir, array $baseParams): string
{
    $newDir = ($currentKey === $column && strtoupper($currentDir) === 'DESC') ? 'ASC' : 'DESC';
    $params = array_merge($baseParams, [
        'sort' => $column,
        'dir' => $newDir,
        'p' => 1,
    ]);

    // Drop empty filter params for cleaner URLs
    $params = array_filter($params, static function ($value) {
        return $value !== null && $value !== '';
    });

    return '?' . http_build_query($params);
}

/**
 * Render a clickable sortable table header cell.
 */
function tableSortTh(string $column, string $label, string $currentKey, string $currentDir, array $baseParams): string
{
    $active = ($currentKey === $column);
    $href = e(tableSortUrl($column, $currentKey, $currentDir, $baseParams));
    $label = e($label);
    $class = 'loka-sort-link no-underline' . ($active ? ' text-primary' : ' text-base-content');

    $icon = '';
    if ($active) {
        $iconName = strtoupper($currentDir) === 'ASC' ? 'bi-arrow-up' : 'bi-arrow-down';
        $icon = ' <i class="bi ' . $iconName . '" aria-hidden="true"></i>';
    } else {
        $icon = ' <i class="bi bi-arrow-down-up opacity-40" aria-hidden="true"></i>';
    }

    return '<th scope="col">'
        . '<a href="' . $href . '" class="' . $class . '" title="Sort by ' . $label . '">'
        . $label . $icon
        . '</a></th>';
}

/**
 * Merge sort params into pagination / filter query arrays.
 */
function tableSortQueryParams(array $sort, array $baseParams = []): array
{
    return array_merge($baseParams, [
        'sort' => $sort['key'],
        'dir' => $sort['dir'],
    ]);
}
