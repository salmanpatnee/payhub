<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRelationshipManagerRequest;
use App\Http\Requests\Admin\UpdateRelationshipManagerRequest;
use App\Models\RelationshipManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RelationshipManagerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/relationship-managers/Index', [
            'rms' => RelationshipManager::orderBy('name')
                ->get()
                ->map(fn (RelationshipManager $rm) => [
                    'id' => $rm->id,
                    'name' => $rm->name,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/relationship-managers/Create');
    }

    public function store(StoreRelationshipManagerRequest $request): RedirectResponse
    {
        RelationshipManager::create($request->validated());

        return redirect()->route('admin.relationship-managers.index')
            ->with('success', 'Relationship manager created.');
    }

    public function edit(RelationshipManager $relationshipManager): Response
    {
        return Inertia::render('admin/relationship-managers/Edit', [
            'rm' => [
                'id' => $relationshipManager->id,
                'name' => $relationshipManager->name,
            ],
        ]);
    }

    public function update(UpdateRelationshipManagerRequest $request, RelationshipManager $relationshipManager): RedirectResponse
    {
        $relationshipManager->update($request->validated());

        return redirect()->route('admin.relationship-managers.index')
            ->with('success', 'Relationship manager updated.');
    }

    public function destroy(RelationshipManager $relationshipManager): RedirectResponse
    {
        if ($relationshipManager->payments()->exists()) {
            return redirect()->route('admin.relationship-managers.index')
                ->with('error', 'Cannot delete an RM that has payments.');
        }

        $relationshipManager->delete();

        return redirect()->route('admin.relationship-managers.index')
            ->with('success', 'Relationship manager deleted.');
    }
}
