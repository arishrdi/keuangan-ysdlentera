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
