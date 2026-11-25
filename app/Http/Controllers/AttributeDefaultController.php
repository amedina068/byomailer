<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAttributeDefaultRequest;
use App\Models\ContactAttribute;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AttributeDefaultController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('attributes/Index', [
            'attributes' => ContactAttribute::query()
                ->orderBy('name')
                ->get()
                ->map(fn (ContactAttribute $attribute): array => [
                    'id' => $attribute->id,
                    'name' => $attribute->name,
                    'default_value' => $attribute->default_value,
                ]),
        ]);
    }

    public function update(UpdateAttributeDefaultRequest $request, int $attributeId): RedirectResponse
    {
        $attribute = ContactAttribute::query()->findOrFail($attributeId);

        $attribute->update(['default_value' => $request->validated('default_value')]);

        return back();
    }
}
