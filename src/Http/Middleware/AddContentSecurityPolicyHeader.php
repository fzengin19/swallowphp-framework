<?php

namespace SwallowPHP\Framework\Http\Middleware;

use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Http\Response;
use SwallowPHP\Framework\Foundation\Config; // Assuming Config class exists
use Closure;

class AddContentSecurityPolicyHeader
{
    /**
     * Handle an incoming request.
     *
     * Adds the Content-Security-Policy header to the response.
     *
     * @param  \SwallowPHP\Framework\Http\Request  $request
     * @param  \Closure  $next
     * @return \SwallowPHP\Framework\Http\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get the response from the next middleware or controller
        $response = $next($request);

        // Check if CSP is enabled in the config
        $cspEnabled = config('security.enabled', false); // Default to false if not set

        if ($cspEnabled === true) {
            $directives = config('security.directives', []);
            $policyString = $this->buildPolicyString($directives);

            if (!empty($policyString)) {
                // Add the header. Use setHeader to overwrite if already exists.
                // Avoid using addHeader as it might append multiple CSP headers which is invalid.
                $response->header('Content-Security-Policy', $policyString);

                // Optional: Add Content-Security-Policy-Report-Only for testing
                // $response->setHeader('Content-Security-Policy-Report-Only', $policyString);
            }
        }

        return $response;
    }

    /**
     * Build the CSP header string from the directives array.
     *
     * @param array $directives
     * @return string
     */
    protected function buildPolicyString(array $directives): string
    {
        $policyParts = [];
        foreach ($directives as $directive => $sources) {
            // Skip empty or null directives
            if (empty($sources)) {
                continue;
            }

            // Handle boolean directives like 'upgrade-insecure-requests'
            if (is_bool($sources) && $sources === true) {
                $policyParts[] = $directive;
            }
            // Handle array-based directives like 'default-src', 'script-src'
            elseif (is_array($sources)) {
                $policyParts[] = $directive . ' ' . implode(' ', $sources);
            }
             // Handle string-based directives like 'report-uri'
             elseif (is_string($sources) && !empty($sources)) {
                 $policyParts[] = $directive . ' ' . $sources;
             }
        }

        return implode('; ', $policyParts);
    }
}
