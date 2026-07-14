<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleAsset;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ArticleAssetController extends Controller
{
    public function store(Request $request, Article $article)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['file', 'link'])],
            'name' => ['required', 'string', 'max:190'],
            'url' => ['required_if:type,link', 'nullable', 'url', 'max:1000'],
            'file' => ['required_if:type,file', 'nullable', 'file', 'max:20480'],
        ]);

        $asset = new ArticleAsset([
            'type' => $data['type'],
            'name' => $data['name'],
            'url' => $data['url'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        if ($data['type'] === 'file' && $request->hasFile('file')) {
            $file = $request->file('file');
            $asset->file_path = $file->store('articles/assets', 'public');
            $asset->original_filename = $file->getClientOriginalName();
            $asset->mime_type = $file->getClientMimeType();
            $asset->file_size = $file->getSize();
        }

        $article->assets()->save($asset);

        return response()->json(['data' => [
            'id' => $asset->id, 'type' => $asset->type, 'name' => $asset->name,
            'url' => $asset->type === 'link' ? $asset->url : $asset->file_url,
        ]], 201);
    }

    public function destroy(ArticleAsset $asset)
    {
        $asset->delete();

        return response()->json(['message' => 'Asset removed.']);
    }
}
