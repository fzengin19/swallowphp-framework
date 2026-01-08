<?php

/**
 * Router Wildcard Parameter Tests
 * 
 * Tests the {param+} wildcard syntax for capturing nested paths
 */

// Helper function that mimics Router's pattern matching logic
function matchRoute(string $routeUri, string $requestPath): ?array
{
    $pattern = preg_quote($routeUri, '/');
    
    // Wildcard: {param+} → captures everything INCLUDING slashes
    $pattern = preg_replace('/\\\\{([a-zA-Z0-9_]+)\\\\\\+\\\\}/', '(?P<$1>.+)', $pattern);
    
    // Normal: {param} → captures everything EXCEPT slashes
    $pattern = preg_replace('/\\\\{([a-zA-Z0-9_]+)\\\\}/', '(?P<$1>[^\\/]+)', $pattern);
    
    $regex = '/^' . $pattern . '$/';
    
    if (preg_match($regex, $requestPath, $matches)) {
        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }
    
    return null;
}

describe('Wildcard Route Parameters {param+}', function () {
    
    it('captures single file path', function () {
        $result = matchRoute('/storage/{path+}', '/storage/file.jpg');
        
        expect($result)->not->toBeNull()
            ->and($result['path'])->toBe('file.jpg');
    });

    it('captures nested path with 2 levels', function () {
        $result = matchRoute('/storage/{path+}', '/storage/banners/image.png');
        
        expect($result)->not->toBeNull()
            ->and($result['path'])->toBe('banners/image.png');
    });

    it('captures deeply nested path', function () {
        $result = matchRoute('/storage/{path+}', '/storage/a/b/c/d/file.txt');
        
        expect($result)->not->toBeNull()
            ->and($result['path'])->toBe('a/b/c/d/file.txt');
    });

    it('captures path with special characters', function () {
        $result = matchRoute('/storage/{path+}', '/storage/files/my-image_2024.png');
        
        expect($result)->not->toBeNull()
            ->and($result['path'])->toBe('files/my-image_2024.png');
    });

});

describe('Normal Route Parameters {param}', function () {
    
    it('captures single segment', function () {
        $result = matchRoute('/user/{id}', '/user/123');
        
        expect($result)->not->toBeNull()
            ->and($result['id'])->toBe('123');
    });

    it('does NOT match nested paths', function () {
        $result = matchRoute('/user/{id}', '/user/123/456');
        
        expect($result)->toBeNull();
    });

    it('captures alphanumeric segment', function () {
        $result = matchRoute('/post/{slug}', '/post/hello-world');
        
        expect($result)->not->toBeNull()
            ->and($result['slug'])->toBe('hello-world');
    });

});

describe('Mixed Route Parameters', function () {
    
    it('handles normal param before wildcard', function () {
        $result = matchRoute('/api/{version}/files/{path+}', '/api/v1/files/docs/readme.md');
        
        expect($result)->not->toBeNull()
            ->and($result['version'])->toBe('v1')
            ->and($result['path'])->toBe('docs/readme.md');
    });

    it('handles multiple normal params', function () {
        $result = matchRoute('/user/{id}/post/{slug}', '/user/42/post/hello-world');
        
        expect($result)->not->toBeNull()
            ->and($result['id'])->toBe('42')
            ->and($result['slug'])->toBe('hello-world');
    });

    it('handles prefix with wildcard', function () {
        $result = matchRoute('/cdn/{version}/{path+}', '/cdn/v2/assets/css/main.css');
        
        expect($result)->not->toBeNull()
            ->and($result['version'])->toBe('v2')
            ->and($result['path'])->toBe('assets/css/main.css');
    });

});

describe('Edge Cases', function () {

    it('wildcard requires at least one character', function () {
        $result = matchRoute('/storage/{path+}', '/storage/');
        
        expect($result)->toBeNull();
    });

    it('matches root level with normal param', function () {
        $result = matchRoute('/{page}', '/about');
        
        expect($result)->not->toBeNull()
            ->and($result['page'])->toBe('about');
    });

    it('static route still works', function () {
        $result = matchRoute('/api/health', '/api/health');
        
        expect($result)->not->toBeNull()
            ->and($result)->toBeEmpty();
    });

});
