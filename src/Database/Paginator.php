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
    protected ?int $total;

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

    /** @var array Query string parameters to append to pagination links. */
    protected array $appendedQuery = [];

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
        // Automatically append existing query string parameters (excluding 'page')
        // This makes appends() additive rather than replacing all query params.
        $this->appendedQuery = $options['query'] ?? [];
    }

    /**
     * Append query string parameters to the paginator links.
     *
     * @param array $query Associative array of query parameters.
     * @return $this
     */
    public function appends(array $query): self
    {
        // Merge new query parameters with existing ones. New ones overwrite old ones.
        $this->appendedQuery = array_merge($this->appendedQuery, $query);
        return $this;
    }

    /**
     * Render the pagination links as HTML.
     *
     * @param string|null $view Optional view name (e.g., 'components.pagination'). 
     *                          If null, uses config('pagination.view') or falls back to default HTML.
     * @param array $data Optional additional data for the view.
     * @return string Rendered HTML pagination links.
     */
    public function links(?string $view = null, array $data = []): string
    {
        if (empty($this->linkStructure)) {
            return '';
        }

        // Determine which view to use
        $viewName = $view ?? config('app.pagination_view', null);

        // If a view is specified, render it
        if ($viewName !== null) {
            return $this->renderView($viewName, $data);
        }

        // Fallback to default Bootstrap-style HTML
        return $this->renderDefaultHtml();
    }

    /**
     * Render pagination using a view file.
     *
     * @param string $viewName The view name (e.g., 'components.pagination')
     * @param array $additionalData Additional data to pass to the view
     * @return string Rendered HTML
     */
    protected function renderView(string $viewName, array $additionalData = []): string
    {
        $viewData = array_merge([
            'paginator' => $this,
            'links' => $this->getProcessedLinks(),
            'hasPages' => $this->lastPage > 1,
            'onFirstPage' => $this->onFirstPage(),
            'hasMorePages' => $this->hasMorePages(),
            'currentPage' => $this->currentPage,
            'lastPage' => $this->lastPage,
            'total' => $this->total,
            'perPage' => $this->perPage,
            'previousPageUrl' => $this->appendQueryToUrl($this->prevPageUrl),
            'nextPageUrl' => $this->appendQueryToUrl($this->nextPageUrl),
            'firstPageUrl' => $this->appendQueryToUrl($this->firstPageUrl),
            'lastPageUrl' => $this->appendQueryToUrl($this->lastPageUrl),
        ], $additionalData);

        // Use the view() helper to render
        if (function_exists('view')) {
            try {
                $response = view($viewName, $viewData);
                return $response->getContent();
            } catch (\Throwable $e) {
                // If view rendering fails, fall back to default HTML
                error_log("Pagination view rendering failed: " . $e->getMessage());
                return $this->renderDefaultHtml();
            }
        }

        // If view() function not available, use default HTML
        return $this->renderDefaultHtml();
    }

    /**
     * Render default Bootstrap-compatible pagination HTML.
     *
     * @return string HTML string
     */
    protected function renderDefaultHtml(): string
    {
        $html = '<ul class="pagination">';

        foreach ($this->getProcessedLinks() as $link) {
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
                $url = $link['url'];
                $html .= '<a class="page-link" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $link['label'] . '</a>';
            }
            $html .= '</li>';
        }

        $html .= '</ul>';
        return $html;
    }

    // --- Accessor methods ---

    public function items(): array
    {
        return $this->items;
    }
    public function total(): int
    {
        return $this->total;
    }
    public function perPage(): int
    {
        return $this->perPage;
    }
    public function currentPage(): int
    {
        return $this->currentPage;
    }
    public function lastPage(): int
    {
        return $this->lastPage;
    }
    public function firstPageUrl(): ?string
    {
        return $this->firstPageUrl;
    }
    public function lastPageUrl(): ?string
    {
        return $this->lastPageUrl;
    }
    public function previousPageUrl(): ?string
    {
        return $this->prevPageUrl;
    } // Alias
    public function nextPageUrl(): ?string
    {
        return $this->nextPageUrl;
    }
    public function path(): string
    {
        return $this->path;
    }
    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }
    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }
    public function isEmpty(): bool
    {
        return empty($this->items);
    }
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    // --- ArrayAccess implementation ---

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }
    public function offsetSet(mixed $offset, mixed $value): void
    { /* Read-only */
    }
    public function offsetUnset(mixed $offset): void
    { /* Read-only */
    }

    // --- Countable implementation ---

    public function count(): int
    {
        return count($this->items);
    }

    // --- IteratorAggregate implementation ---

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    // --- JsonSerializable implementation ---

    public function jsonSerialize(): array
    {
        $processedLinks = $this->getProcessedLinks(); // Get links with appended query
        return [
            'current_page' => $this->currentPage,
            'data' => $this->items,
            'first_page_url' => $this->appendQueryToUrl($this->firstPageUrl),
            'from' => $this->firstItem(),
            'last_page' => $this->lastPage,
            'last_page_url' => $this->appendQueryToUrl($this->lastPageUrl),
            'links' => $processedLinks, // Use processed links for API response
            'next_page_url' => $this->appendQueryToUrl($this->nextPageUrl),
            'path' => $this->path, // Path usually doesn't include query string
            'per_page' => $this->perPage,
            'prev_page_url' => $this->appendQueryToUrl($this->prevPageUrl),
            'to' => $this->lastItem(),
            'total' => $this->total,
        ];
    }

    /**
     * Get the structured links array with appended query parameters applied to URLs.
     *
     * @return array
     */
    protected function getProcessedLinks(): array
    {
        $processedLinks = [];
        foreach ($this->linkStructure as $link) {
            $processedLink = $link;
            if ($processedLink['url'] !== null) {
                $processedLink['url'] = $this->appendQueryToUrl($processedLink['url']);
            }
            $processedLinks[] = $processedLink;
        }
        return $processedLinks;
    }

    /**
     * Helper function to append the stored query parameters to a given URL.
     *
     * @param string|null $url The base URL (can be null).
     * @return string|null The URL with appended query parameters, or null if input was null.
     */
    protected function appendQueryToUrl(?string $url): ?string
    {
        if ($url === null || empty($this->appendedQuery)) {
            return $url;
        }

        // Parse the existing URL to separate path and existing query
        $urlParts = parse_url($url);
        if ($urlParts === false) {
            return $url; // Cannot parse URL, return original
        }

        $existingQuery = [];
        if (isset($urlParts['query'])) {
            parse_str($urlParts['query'], $existingQuery);
        }

        // Merge existing query with appended query (appended takes precedence)
        // Important: Ensure 'page' from the original URL is preserved if it exists
        $finalQuery = array_merge($existingQuery, $this->appendedQuery);

        // Rebuild the URL
        $scheme = isset($urlParts['scheme']) ? $urlParts['scheme'] . '://' : '';
        $host = $urlParts['host'] ?? '';
        $port = isset($urlParts['port']) ? ':' . $urlParts['port'] : '';
        $user = $urlParts['user'] ?? '';
        $pass = isset($urlParts['pass']) ? ':' . $urlParts['pass'] : '';
        $pass = ($user || $pass) ? "$pass@" : '';
        $path = $urlParts['path'] ?? '';
        $queryString = http_build_query($finalQuery);
        $fragment = isset($urlParts['fragment']) ? '#' . $urlParts['fragment'] : '';

        return $scheme . $user . $pass . $host . $port . $path . '?' . $queryString . $fragment;
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
