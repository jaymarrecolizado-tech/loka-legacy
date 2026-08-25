<?php
/**
 * Shared list pagination: default 10, choices 10 / 25 / 50 / 100.
 * Bootstrap 5 markup.
 */

if (!defined('PER_PAGE_OPTIONS')) {
    define('PER_PAGE_OPTIONS', [10, 25, 50, 100]);
}
if (!defined('DEFAULT_PER_PAGE')) {
    define('DEFAULT_PER_PAGE', 10);
}

/**
 * Resolve per-page from request (clamped to allowed options).
 */
function resolvePerPage(string $param = 'per_page', ?int $default = null): int
{
    $default = $default ?? DEFAULT_PER_PAGE;
    if (!in_array($default, PER_PAGE_OPTIONS, true)) {
        $default = DEFAULT_PER_PAGE;
    }
    $raw = getInt($param, $default);
    return in_array($raw, PER_PAGE_OPTIONS, true) ? $raw : $default;
}

function resolveListPage(string $param = 'p'): int
{
    return max(1, getInt($param, 1));
}

/**
 * @return array{page:int,perPage:int,total:int,totalPages:int,offset:int,from:int,to:int}
 */
function listPaginationState(int $total, ?int $page = null, ?int $perPage = null): array
{
    $perPage = $perPage ?? resolvePerPage();
    $page = $page ?? resolveListPage();
    $total = max(0, $total);
    $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $from = $total === 0 ? 0 : $offset + 1;
    $to = min($offset + $perPage, $total);

    return [
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
        'totalPages' => $totalPages,
        'offset' => $offset,
        'from' => $from,
        'to' => $to,
    ];
}

/**
 * Per-page select for filter forms (auto-submits on change).
 */
function perPageFieldHtml(int $current, string $selectClass = 'form-select form-select-sm'): string
{
    $html = '<div class="d-flex flex-column gap-1" style="min-width:100px;">';
    $html .= '<label class="form-label small fw-semibold text-muted text-uppercase mb-0">Per page</label>';
    $html .= '<select name="per_page" class="' . e($selectClass) . '" onchange="this.form.submit()">';
    foreach (PER_PAGE_OPTIONS as $n) {
        $sel = ((int) $n === (int) $current) ? ' selected' : '';
        $html .= '<option value="' . (int) $n . '"' . $sel . '>' . (int) $n . '</option>';
    }
    $html .= '</select></div>';
    return $html;
}

function listPaginationUrl(array $params, int $pageNum, string $pageParam = 'p'): string
{
    $params[$pageParam] = $pageNum;
    $params = array_filter($params, static function ($value) {
        return $value !== null && $value !== '';
    });
    return '?' . http_build_query($params);
}

/**
 * Compact footer: "Showing X–Y of Z" + per-page (10/25/50/100) + page links.
 * Default page size is always 10 via DEFAULT_PER_PAGE / resolvePerPage().
 */
function listPaginationFooter(array $state, array $queryParams, string $pageParam = 'p'): string
{
    $total = (int) $state['total'];
    if ($total <= 0) {
        return '';
    }

    $page = (int) $state['page'];
    $totalPages = (int) $state['totalPages'];
    $from = (int) $state['from'];
    $to = (int) $state['to'];
    $perPage = (int) $state['perPage'];
    if (!in_array($perPage, PER_PAGE_OPTIONS, true)) {
        $perPage = DEFAULT_PER_PAGE;
    }

    $queryParams['per_page'] = $perPage;

    $html = '<div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3 mt-3">';
    $html .= '<div class="d-flex flex-wrap align-items-center gap-3">';
    $html .= '<p class="small text-muted mb-0">Showing ' . $from . '–' . $to . ' of ' . number_format($total) . '</p>';

    // Always expose 10/25/50/100 on every paginated list
    $html .= '<label class="d-flex align-items-center gap-2 small text-muted mb-0">';
    $html .= '<span>Per page</span>';
    $html .= '<select class="form-select form-select-sm w-auto" onchange="window.location.href=this.value">';
    foreach (PER_PAGE_OPTIONS as $n) {
        $optParams = $queryParams;
        $optParams['per_page'] = (int) $n;
        $optParams[$pageParam] = 1;
        $href = e(listPaginationUrl($optParams, 1, $pageParam));
        $sel = ((int) $n === $perPage) ? ' selected' : '';
        $html .= '<option value="' . $href . '"' . $sel . '>' . (int) $n . '</option>';
    }
    $html .= '</select></label>';
    $html .= '</div>';

    if ($totalPages > 1) {
        $html .= '<ul class="pagination pagination-sm mb-0">';
        if ($page > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . e(listPaginationUrl($queryParams, 1, $pageParam)) . '" title="First">&laquo;</a></li>';
            $html .= '<li class="page-item"><a class="page-link" href="' . e(listPaginationUrl($queryParams, $page - 1, $pageParam)) . '" title="Previous">&lsaquo;</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
            $html .= '<li class="page-item disabled"><span class="page-link">&lsaquo;</span></li>';
        }

        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
        for ($i = $start; $i <= $end; $i++) {
            if ($i === $page) {
                $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
            } else {
                $html .= '<li class="page-item"><a class="page-link" href="' . e(listPaginationUrl($queryParams, $i, $pageParam)) . '">' . $i . '</a></li>';
            }
        }

        if ($page < $totalPages) {
            $html .= '<li class="page-item"><a class="page-link" href="' . e(listPaginationUrl($queryParams, $page + 1, $pageParam)) . '" title="Next">&rsaquo;</a></li>';
            $html .= '<li class="page-item"><a class="page-link" href="' . e(listPaginationUrl($queryParams, $totalPages, $pageParam)) . '" title="Last">&raquo;</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">&rsaquo;</span></li>';
            $html .= '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
        }
        $html .= '</ul>';
    }

    $html .= '</div>';
    return $html;
}
