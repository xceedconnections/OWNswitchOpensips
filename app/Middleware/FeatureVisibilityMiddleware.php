<?php

declare(strict_types=1);

namespace Ownswitch\Middleware;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Access\FeatureVisibilityService;

/** Blocks paths that the vendor has disabled for the current audience. */
final class FeatureVisibilityMiddleware implements MiddlewareInterface
{
    private static bool $ensured = false;

    public function __construct(private FeatureVisibilityService $features)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!self::$ensured) {
            try {
                $this->features->ensureSchemaAndVendor();
            } catch (\Throwable) {
            }
            self::$ensured = true;
        }
        $user = $request->attr('user');
        $user = is_array($user) ? $user : null;
        $path = (string) ($request->path ?? '/');
        if (!$this->features->pathAllowed($user, $path)) {
            $body = '403 Forbidden — this page is disabled for your account.';
            return $request->wantsJson()
                ? Response::json(['error' => 'feature_disabled'], 403)
                : new Response($body, 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        return $next($request);
    }
}
