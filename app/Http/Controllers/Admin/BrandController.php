<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBrandRequest;
use App\Http\Requests\Admin\UpdateBrandRequest;
use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BrandController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/brands/Index', [
            'brands' => Brand::withCount('stripeAccounts')
                ->orderBy('name')
                ->get()
                ->map(fn (Brand $brand) => [
                    'id'                    => $brand->id,
                    'name'                  => $brand->name,
                    'slug'                  => $brand->slug,
                    'logo_url'              => $brand->logo_path
                        ? Storage::disk('public')->url($brand->logo_path)
                        : null,
                    'primary_color'         => $brand->primary_color,
                    'secondary_color'       => $brand->secondary_color,
                    'stripe_accounts_count' => $brand->stripe_accounts_count,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/brands/Create');
    }

    public function store(StoreBrandRequest $request): RedirectResponse
    {
        $data         = $request->safe()->except('logo');
        $data['slug'] = $this->generateUniqueSlug($data['name']);

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('brands', 'public');
        }

        Brand::create($data);

        return redirect()->route('admin.brands.index')
            ->with('success', 'Brand created.');
    }

    public function edit(Brand $brand): Response
    {
        return Inertia::render('admin/brands/Edit', [
            'brand' => [
                'id'              => $brand->id,
                'name'            => $brand->name,
                'logo_url'        => $brand->logo_path
                    ? Storage::disk('public')->url($brand->logo_path)
                    : null,
                'primary_color'   => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
            ],
        ]);
    }

    public function update(UpdateBrandRequest $request, Brand $brand): RedirectResponse
    {
        $data = $request->safe()->except('logo');

        if ($request->hasFile('logo')) {
            if ($brand->logo_path) {
                Storage::disk('public')->delete($brand->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('brands', 'public');
        }

        $brand->update($data);

        return redirect()->route('admin.brands.index')
            ->with('success', 'Brand updated.');
    }

    private function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 1;

        while (Brand::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
