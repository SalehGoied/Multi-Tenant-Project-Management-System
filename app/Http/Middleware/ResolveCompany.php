<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCompany
{
    public function __construct(private CurrentCompany $currentCompany) {}

    /**
     * Bind the authenticated user's company. The client never supplies it.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        $company = $user instanceof User ? $user->company : null;

        if (! $company instanceof Company) {
            abort(403, 'User is not assigned to a company.');
        }

        $this->currentCompany->set($company);

        return $next($request);
    }
}
