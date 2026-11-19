<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Only allow users whose role is in the given list. Use "staff" to allow every staff role.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_if($user === null, Response::HTTP_FORBIDDEN);

        $isAllowed = collect($roles)->contains(
            fn (string $role): bool => $role === 'staff'
                ? $user->isStaff()
                : $user->role === Role::from($role),
        );

        abort_unless($isAllowed, Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
