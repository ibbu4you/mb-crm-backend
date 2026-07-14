<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeadNoteResource;
use App\Models\Lead;
use App\Models\LeadNote;
use Illuminate\Http\Request;

class LeadNoteController extends Controller
{
    public function store(Request $request, Lead $lead)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $note = $lead->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);
        $lead->update(['last_activity_at' => now()]);

        return (new LeadNoteResource($note->load('user')))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, LeadNote $note)
    {
        if ($note->user_id !== $request->user()->id && ! $request->user()->can('leads.view.all')) {
            return response()->json(['message' => 'You can only delete your own notes.'], 403);
        }
        $note->delete();

        return response()->json(['message' => 'Note deleted.']);
    }
}
