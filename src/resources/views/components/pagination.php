<?php
/**
 * Default Pagination View
 * 
 * Available variables:
 * - $paginator: Paginator instance
 * - $links: Array of link items with 'url', 'label', 'active', 'disabled' keys
 * - $hasPages: bool - true if more than 1 page
 * - $onFirstPage: bool
 * - $hasMorePages: bool
 * - $currentPage: int
 * - $lastPage: int
 * - $total: int
 * - $perPage: int
 * - $previousPageUrl: string|null
 * - $nextPageUrl: string|null
 * - $firstPageUrl: string|null
 * - $lastPageUrl: string|null
 */
?>
<?php if ($hasPages): ?>
    <nav aria-label="Sayfa navigasyonu">
        <ul class="pagination">
            <?php foreach ($links as $link): ?>
                <?php
                $class = 'page-item';
                if ($link['active'])
                    $class .= ' active';
                if ($link['disabled'] ?? false)
                    $class .= ' disabled';
                ?>
                <li class="<?= $class ?>">
                    <?php if ($link['url'] === null || ($link['disabled'] ?? false)): ?>
                        <span class="page-link"><?= $link['label'] ?></span>
                    <?php else: ?>
                        <a class="page-link" href="<?= htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= $link['label'] ?>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
<?php endif; ?>