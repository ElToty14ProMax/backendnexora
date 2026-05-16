<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        $allowedOrigin = $this->allowedOrigin($origin);

        if ($request->isMethod('OPTIONS')) {
            return response('', 204)->withHeaders($this->headers($allowedOrigin));
        }

        $response = $next($request);
        foreach ($this->headers($allowedOrigin) as $header => $value) {
            $response->headers->set($header, $value);
        }

        return $response;
    }

    private function allowedOrigin(?string $origin): string
    {
        if ($origin === null || $origin === '') {
            return '*';
        }

        $configured = array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env('NEXORA_CORS_ORIGINS', '*'))
        ));

        if (in_array('*', $configured, true) || in_array($origin, $configured, true)) {
            return $origin;
        }

        return $configured[0] ?? '*';
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $origin): array
    {
        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Admin-Token, Accept',
            'Access-Control-Max-Age' => '86400',
            'Vary' => 'Origin',
        ];
    }
}
