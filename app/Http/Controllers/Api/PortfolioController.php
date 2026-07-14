<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortfolioImage;
use App\Models\PortfolioItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class PortfolioController extends Controller
{
    private const TYPES = ['website', 'video', 'graphic', 'automation', 'article'];

    /** Fetch og:image + title from a URL (for the "auto-preview" button). */
    public function preview(Request $request)
    {
        $url = $request->validate(['url' => ['required', 'url']])['url'];
        try {
            $html = Http::timeout(8)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($url)->body();
            $og = fn ($p) => preg_match('/<meta[^>]+property=["\']og:'.$p.'["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) ? html_entity_decode($m[1]) : null;
            $title = $og('title');
            if (! $title && preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
                $title = trim($m[1]);
            }

            return response()->json(['image' => $og('image'), 'title' => $title, 'description' => $og('description')]);
        } catch (\Throwable $e) {
            return response()->json(['image' => null, 'title' => null, 'description' => null]);
        }
    }

    public function index(Request $request)
    {
        $q = PortfolioItem::with('images');
        if ($type = $request->input('type')) {
            $q->where('type', $type);
        }
        if (! $request->user()->can('portfolio.manage')) {
            $q->where('is_active', true);
        }

        return response()->json(['data' => $q->orderBy('sort_order')->latest()->get()->map(fn ($p) => $this->row($p))]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(self::TYPES)],
            'title' => ['required', 'string', 'max:190'],
            'url' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string'],
            'credentials' => ['nullable', 'array'],
            'image' => ['nullable', 'image', 'max:8192'],
        ]);
        $data['created_by'] = $request->user()->id;
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('portfolio', 'public');
        }
        $item = PortfolioItem::create($data);

        return response()->json(['data' => $this->row($item->load('images'))], 201);
    }

    public function update(Request $request, PortfolioItem $portfolioItem)
    {
        $data = $request->validate([
            'type' => ['sometimes', Rule::in(self::TYPES)],
            'title' => ['sometimes', 'string', 'max:190'],
            'url' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string'],
            'credentials' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'image', 'max:8192'],
        ]);
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('portfolio', 'public');
        }
        $portfolioItem->update($data);

        return response()->json(['data' => $this->row($portfolioItem->fresh()->load('images'))]);
    }

    public function destroy(PortfolioItem $portfolioItem)
    {
        $portfolioItem->delete();

        return response()->json(['message' => 'Removed.']);
    }

    public function addImage(Request $request, PortfolioItem $portfolioItem)
    {
        $request->validate(['image' => ['required', 'image', 'max:8192']]);
        $portfolioItem->images()->create(['image_path' => $request->file('image')->store('portfolio', 'public')]);

        return response()->json(['data' => $this->row($portfolioItem->fresh()->load('images'))]);
    }

    public function removeImage(PortfolioImage $image)
    {
        $image->delete();

        return response()->json(['message' => 'Image removed.']);
    }

    private function row(PortfolioItem $p): array
    {
        return [
            'id' => $p->id, 'type' => $p->type, 'title' => $p->title, 'url' => $p->url,
            'description' => $p->description, 'credentials' => $p->credentials, 'is_active' => $p->is_active,
            'image_url' => $p->image_url,
            'images' => $p->images->map(fn ($i) => ['id' => $i->id, 'url' => $i->url]),
        ];
    }
}
