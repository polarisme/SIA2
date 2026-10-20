<?php
declare(strict_types=1);

// Shared server-side pagination for every data table across PMS, AIS, Integrated,
// and User Management. The default page size is 5 everywhere per the platform
// standard; callers may offer 10/25/50 via the rendered page-size selector.
const PAGINATION_PER_PAGE_OPTIONS = [5, 10, 25, 50];
const PAGINATION_DEFAULT_PER_PAGE = 5;

/**
 * Resolves the current page/per_page/offset from the query string, clamped to safe values.
 * Pass a $prefix (e.g. "flock_") when a single page hosts more than one independently
 * paginated table, so each table reads its own "{prefix}page"/"{prefix}per_page" params
 * instead of colliding on the same "page"/"per_page" — the default (no prefix) covers the
 * common one-table-per-page case.
 */
function paginate_params(string $prefix = ''): array
{
    $page = max(1, (int) ($_GET[$prefix . 'page'] ?? 1));
    $perPage = (int) ($_GET[$prefix . 'per_page'] ?? PAGINATION_DEFAULT_PER_PAGE);
    if (!in_array($perPage, PAGINATION_PER_PAGE_OPTIONS, true)) {
        $perPage = PAGINATION_DEFAULT_PER_PAGE;
    }

    return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage];
}

/**
 * Renders the standard pagination bar: a "Showing X-Y of Z" label, a rows-per-page
 * selector (posts back with page reset to 1), and Previous/Next controls — all
 * preserving every other current query-string parameter (search, filters, sort).
 *
 * @param array $currentParams Usually $_GET as received by the page (page/per_page included or not, both are handled).
 * @param string $prefix Must match the $prefix passed to paginate_params() for this same table.
 */
function render_pagination_controls(int $page, int $perPage, int $totalRows, array $currentParams, string $baseUrl, string $prefix = ''): void
{
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page = min(max(1, $page), $totalPages);
    $firstRow = $totalRows === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $lastRow = min($totalRows, $page * $perPage);
    $pageKey = $prefix . 'page';
    $perPageKey = $prefix . 'per_page';
    $filterParams = $currentParams;
    unset($filterParams[$pageKey], $filterParams[$perPageKey]);

    $buildUrl = static function (int $targetPage) use ($filterParams, $perPage, $baseUrl, $pageKey, $perPageKey): string {
        $query = http_build_query($filterParams + [$perPageKey => $perPage, $pageKey => $targetPage]);

        return $baseUrl . ($query !== '' ? '?' . $query : '');
    };
    ?>
    <div class="pagination-bar d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 border-top">
        <div class="small text-secondary">
            <?= $totalRows === 0 ? 'No records found.' : sprintf('Showing %s&ndash;%s of %s', number_format($firstRow), number_format($lastRow), number_format($totalRows)) ?>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <?php if ($totalRows > PAGINATION_DEFAULT_PER_PAGE): ?>
                <form method="get" action="<?= h($baseUrl) ?>" class="d-flex align-items-center gap-1">
                    <?php foreach ($filterParams as $key => $value): ?>
                        <?php foreach ((array) $value as $v): ?>
                            <input type="hidden" name="<?= h((string) $key) ?>" value="<?= h((string) $v) ?>">
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <label class="small text-secondary mb-0" for="per_page_select_<?= h(md5($baseUrl . $prefix)) ?>">Rows per page</label>
                    <select class="form-select form-select-sm" id="per_page_select_<?= h(md5($baseUrl . $prefix)) ?>" name="<?= h($perPageKey) ?>" style="width:auto" onchange="this.form.submit()" aria-label="Rows per page">
                        <?php foreach (PAGINATION_PER_PAGE_OPTIONS as $option): ?>
                            <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Table pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= h($buildUrl(max(1, $page - 1))) ?>" aria-label="Previous page">&laquo;</a>
                        </li>
                        <li class="page-item disabled"><span class="page-link">Page <?= $page ?> of <?= $totalPages ?></span></li>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= h($buildUrl(min($totalPages, $page + 1))) ?>" aria-label="Next page">&raquo;</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/** A hidden <input> that preserves the current per_page across a filter/search form submit (which should itself omit "page" so it naturally resets to page 1). Pass the same $prefix used for this table's paginate_params()/render_pagination_controls(). */
function pagination_preserve_per_page_field(int $perPage, string $prefix = ''): void
{
    echo '<input type="hidden" name="' . h($prefix . 'per_page') . '" value="' . (int) $perPage . '">';
}
