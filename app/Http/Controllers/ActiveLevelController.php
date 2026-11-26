<?php

namespace App\Http\Controllers;

use App\Models\Level;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActiveLevelController extends Controller
{
    /**
     * Switch the level the staff member is working in.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        abort_if($request->user()->level_id !== null, 403, 'Akun Anda terkunci pada satu jenjang.');

        $validated = $request->validate([
            'level' => ['required', Rule::anyOf([
                [Rule::in([Level::ALL])],
                ['integer', Rule::exists(Level::class, 'id')],
            ])],
        ]);

        Level::activate($validated['level'] === Level::ALL ? null : Level::query()->findOrFail((int) $validated['level']));

        return $request->boolean('stay')
            ? back()
            : redirect()->intended(route('dashboard'));
    }
}
