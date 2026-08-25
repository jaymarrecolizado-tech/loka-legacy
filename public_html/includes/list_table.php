<?php
/**
 * Shared list-table UI helpers (search field markup). Bootstrap 5.
 */

/**
 * Resolve list search text from q (preferred) or legacy search param.
 */
if (!function_exists('listSearchQuery')) {
    function listSearchQuery(string $primary = 'q', string $legacy = 'search', int $maxLen = 100): string
    {
        $raw = '';
        if (function_exists('getSafe')) {
            $raw = (string) getSafe($primary, '', $maxLen);
            if (trim($raw) === '' && $legacy !== '') {
                $raw = (string) getSafe($legacy, '', $maxLen);
            }
        } elseif (function_exists('get')) {
            $raw = (string) get($primary, '');
            if (trim($raw) === '' && $legacy !== '') {
                $raw = (string) get($legacy, '', $maxLen);
            }
        } else {
            $raw = (string) ($_GET[$primary] ?? '');
            if (trim($raw) === '' && $legacy !== '') {
                $raw = (string) ($_GET[$legacy] ?? '');
            }
        }

        $q = trim($raw);
        return function_exists('mb_substr') ? mb_substr($q, 0, $maxLen) : substr($q, 0, $maxLen);
    }
}

/**
 * Standard Search field for filter forms (always visible on data tables).
 */
if (!function_exists('listSearchFieldHtml')) {
    function listSearchFieldHtml(
        string $value,
        string $placeholder = 'Search...',
        string $inputClass = 'form-control form-control-sm',
        string $name = 'q'
    ): string {
        $html = '<div class="d-flex flex-column gap-1 flex-grow-1" style="min-width:200px;">';
        $html .= '<label class="form-label small fw-semibold text-muted text-uppercase mb-0">Search</label>';
        $html .= '<input type="text" name="' . e($name) . '" value="' . e($value) . '"';
        $html .= ' placeholder="' . e($placeholder) . '" class="' . e($inputClass) . '" autocomplete="off">';
        $html .= '</div>';
        return $html;
    }
}
