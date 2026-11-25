<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Registration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The settings of the instance, for the owner.
 */
class SettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('admin/Settings', [
            'registrationMode' => Registration::mode(),
            'defaultMode' => Registration::defaultMode(),
            'modeIsStored' => Registration::isStored(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'registration_mode' => ['required', 'string', Rule::in(Registration::MODES)],
        ]);

        Registration::setMode($validated['registration_mode']);

        return redirect()->route('admin.settings.edit')->with('success', 'The settings are saved.');
    }
}
