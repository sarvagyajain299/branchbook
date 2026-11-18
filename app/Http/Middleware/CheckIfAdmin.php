<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for every Backpack admin page. Staff log in through the normal /login page; only HQ
 * administrators and branch managers may enter the admin panel. Front desk and advisors get 403.
 * What a manager may see INSIDE the panel is limited per branch by each CRUD controller.
 */
class CheckIfAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (backpack_auth()->guest()) {
            return $request->expectsJson()
                ? response('Unauthenticated.', 401)
                : redirect()->guest(route('login'));
        }

        abort_unless(in_array(backpack_user()->role, [Role::HqAdmin, Role::BranchManager], true), 403);

        // Backpack pages need inline scripts and styles; SecurityHeaders gives them their own policy.
        $request->attributes->set('csp_profile', 'backpack');

        return $next($request);
    }
}
