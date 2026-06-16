<?php

namespace App\Http\Middleware;

use App\Support\PanelState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * If the panel has not completed first-run setup, force every request to the
 * setup wizard. Once setup is complete, the wizard routes redirect away.
 */
class EnsureSetupComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $complete = PanelState::isSetupComplete();

        if (! $complete && ! $request->is('setup', 'setup/*', 'up')) {
            return redirect()->route('setup.index');
        }

        if ($complete && $request->is('setup', 'setup/*')) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
