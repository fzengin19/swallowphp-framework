<?php

namespace SwallowPHP\Framework\Database;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use ArrayIterator;
use JsonSerializable;

/**
 * Handles pagination results and provides methods for rendering links.
 * Implements interfaces to allow treating the Paginator like an array for the items.
 */
class Paginator implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /** @var array The items for the current page. */
    protected array $items;

    /** @var int Total number of items. */
    protected int $total;

    /** @var int Number of items per page. */
    protected int $perPage;

    /** @var int Current page number. */
    protected int $currentPage;

    /** @var int Last page number. */
    protected int $lastPage;

    /** @var string|null URL for the first page. */
    protected ?string $firstPageUrl;

    /** @var string|null URL for the last page. */
    protected ?string $lastPageUrl;

    /** @var string|null URL for the previous page. */
    protected ?string $prevPageUrl;

    /** @var string|null URL for the next page. */
    protected ?string $nextPageUrl;

    /** @var string Base path for pagination URLs. */
    protected string $path;

    /** @var array Structured array of pagination links. */
    protected array $linkStructure;

    /**
     * Paginator constructor.
     *
     * @param array $items Items for the current page.
     * @param int $total Total number of items.
     * @param int $perPage Items per page.
     * @param int $currentPage Current page number.
     * @param array $options Additional pagination data (lastPage, urls, path, linkStructure).
     */
    public function __construct(array $items, int $total, int $perPage, int $currentPage, array $options = [])
    {
        $this->items = $items;
        $this->total = $total;
        $this->perPage = $perPage;
        $this->currentPage = $currentPage;

        $this->lastPage = $options['last_page'] ?? 0;
        $this->firstPageUrl = $options['first_page_url'] ?? null;
        $this->lastPageUrl = $options['last_page_url'] ?? null;
        $this->prevPageUrl = $options['prev_page_url'] ?? null;
        $this->nextPageUrl = $options['next_page_url'] ?? null;
        $this->path = $options['path'] ?? '/';
        $this->linkStructure = $options['pagination_links'] ?? [];
    }

    /**
     * Render the pagination links as HTML.
     *
     * @param string|null $view Optional view name (not implemented yet, defaults to basic HTML).
     * @param array $data Optional data for the view.
     * @return string Rendered HTML pagination links.
     */
    public function links(?string $view = null, array $data = []): string
    {
        // Basic HTML rendering for now. View integration can be added later.
        if (empty($this->linkStructure)) {
            return '';
        }

        $html = '<ul class="pagination">'; // Basic bootstrap-like structure

        foreach ($this->linkStructure as $link) {
            $class = 'page-item';
            if ($link['active']) {
                $class .= ' active';
            }
            if ($link['disabled'] ?? false) {
                $class .= ' disabled';
            }

            $html .= '<li class="' . $class . '">';
            if ($link['url'] === null || ($link['disabled'] ?? false)) {
                $html .= '<span class="page-link">' . $link['label'] . '</span>';
            } else {
                $html .= '<a class="page-link" href="' . htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8') . '">' . $link['label'] . '</a>';
            }
            $html .= '</li>';
        }

        $html .= '</ul>';
        return $html;
    }

    // --- Accessor methods ---

    public function items(): array { return $this->items; }
    public function total(): int { return $this->total; }
    public function perPage(): int { return $this->perPage; }
    public function currentPage(): int { return $this->currentPage; }
    public function lastPage(): int { return $this->lastPage; }
    public function firstPageUrl(): ?string { return $this->firstPageUrl; }
    public function lastPageUrl(): ?string { return $this->lastPageUrl; }
    public function previousPageUrl(): ?string { return $this->prevPageUrl; } // Alias
    public function nextPageUrl(): ?string { return $this->nextPageUrl; }
    public function path(): string { return $this->path; }
    public function hasMorePages(): bool { return $this->currentPage < $this->lastPage; }
    public function onFirstPage(): bool { return $this->currentPage <= 1; }
    public function isEmpty(): bool { return empty($this->items); }
    public function isNotEmpty(): bool { return !$this->isEmpty(); }

    // --- ArrayAccess implementation ---

    public function offsetExists(mixed $offset): bool { return isset($this->items[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->items[$offset]; }
    public function offsetSet(mixed $offset, mixed $value): void { /* Read-only */ }
    public function offsetUnset(mixed $offset): void { /* Read-only */ }

    // --- Countable implementation ---

    public function count(): int { return count($this->items); }

    // --- IteratorAggregate implementation ---

    public function getIterator(): ArrayIterator { return new ArrayIterator($this->items); }

    // --- JsonSerializable implementation ---

    public function jsonSerialize(): array
    {
        return [
            'current_page' => $this->currentPage,
            'data' => $this->items,
            'first_page_url' => $this->firstPageUrl,
            'from' => $this->firstItem(),
            'last_page' => $this->lastPage,
            'last_page_url' => $this->lastPageUrl,
            'links' => $this->linkStructure, // Include the structure for API use
            'next_page_url' => $this->nextPageUrl,
            'path' => $this->path,
            'per_page' => $this->perPage,
            'prev_page_url' => $this->prevPageUrl,
            'to' => $this->lastItem(),
            'total' => $this->total,
        ];
    }

    /** Get the result number of the first item in the results. */
    protected function firstItem(): ?int
    {
        return count($this->items) > 0 ? ($this->currentPage - 1) * $this->perPage + 1 : null;
    }

    /** Get the result number of the last item in the results. */
    protected function lastItem(): ?int
    {
        return count($this->items) > 0 ? $this->firstItem() + count($this->items) - 1 : null;
    }
}
