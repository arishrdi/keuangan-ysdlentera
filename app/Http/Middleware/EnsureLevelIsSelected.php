<?php

namespace App\Http\Middleware;

use App\Models\Level;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLevelIsSelected
{
    /**
     * Send principals and treasurers to the level picker until they choose a level.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->role->mustSelectLevelAfterLogin() && ! Level::hasActiveSelection()) {
            return redirect()->guest(route('levels.select'));
        }

        return $next($request);
    }
}
