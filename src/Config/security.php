<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy (CSP)
    |--------------------------------------------------------------------------
    |
    | Define the Content Security Policy directives for your application.
    | This helps prevent cross-site scripting (XSS) and other injection attacks.
    | Use 'self' to allow resources from the same origin, specify domains,
    | or use '*' for wildcard (use with caution). Use `null` or empty array
    | to omit a directive. Use `false` to disable CSP entirely.
    |
    | See: https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP
    |
    */

    'enabled' => env('CSP_ENABLED', true), // Enable CSP by default

    'directives' => [
        // Default policy for fetching resources. Fallback for others.
        'default-src' => ["'self'"],

        // Defines valid sources for JavaScript.
        'script-src' => ["'self'"], // Add 'unsafe-inline', 'unsafe-eval' or specific domains/hashes if needed

        // Defines valid sources for stylesheets.
        'style-src' => ["'self'"], // Add 'unsafe-inline' or specific domains/hashes if needed

        // Defines valid sources of images and favicons.
        'img-src' => ["'self'", 'data:'], // Allow self and data URIs (common for small images)

        // Applies to XMLHttpRequest (AJAX), WebSocket, fetch(), etc.
        'connect-src' => ["'self'"],

        // Defines valid sources for fonts loaded using @font-face.
        'font-src' => ["'self'"],

        // Defines valid sources for plugins (e.g., <object>, <embed>, <applet>).
        'object-src' => ["'none'"], // Recommended to disable plugins

        // Defines valid sources for loading media using <audio> and <video>.
        'media-src' => ["'self'"],

        // Defines valid sources for web workers and nested browsing contexts using <frame> and <iframe>.
        'frame-src' => ["'self'"],

        // Specifies valid parents that may embed a page using <frame>, <iframe>, <object>, <embed>, or <applet>.
        'frame-ancestors' => ["'self'"],

        // Defines valid sources for form submissions.
        'form-action' => ["'self'"],

        // Restricts the URLs which can be used in a document's <base> element.
        'base-uri' => ["'self'"],

        // Instructs the browser to report attempts to violate the CSP.
        // Example: 'report-uri /csp-report-endpoint';
        'report-uri' => null,

        // Instructs user agents to treat all of a site's insecure URLs (those served over HTTP)
        // as though they have been replaced with secure URLs (those served over HTTPS).
        // 'upgrade-insecure-requests' => true, // Uncomment if your site is fully HTTPS

        // Requires the use of Subresource Integrity (SRI) for scripts or styles on the page.
        // 'require-sri-for' => ['script', 'style'], // Uncomment to enforce SRI
    ],

];
