<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappCampaign;
use App\Models\WhatsappConversation;
use App\Models\WhatsappGroup;
use App\Models\WhatsappGroupMember;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTemplate;
use App\Services\WhatsAppService;

class WhatsAppDashboardController extends Controller
{
    public function __construct(private WhatsAppService $wa) {}

    public function __invoke()
    {
        $today = today()->toDateString();

        // Message counters
        $out = WhatsappMessage::where('direction', 'out');
        $in = WhatsappMessage::where('direction', 'in');
        $delivered = WhatsappMessage::where('direction', 'out')->whereIn('status', ['sent', 'delivered', 'read'])->count();
        $failed = WhatsappMessage::where('direction', 'out')->whereIn('status', ['failed', 'error'])->count();

        // 14-day in/out series
        $since = now()->subDays(13)->startOfDay();
        $outByDay = WhatsappMessage::where('direction', 'out')->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) d, COUNT(*) c')->groupBy('d')->pluck('c', 'd');
        $inByDay = WhatsappMessage::where('direction', 'in')->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) d, COUNT(*) c')->groupBy('d')->pluck('c', 'd');
        $series = collect(range(13, 0))->map(function ($i) use ($outByDay, $inByDay) {
            $d = now()->subDays($i)->toDateString();

            return ['date' => $d, 'sent' => (int) ($outByDay[$d] ?? 0), 'received' => (int) ($inByDay[$d] ?? 0)];
        });

        // Unread inbox threads
        $lastInbound = WhatsappMessage::where('direction', 'in')->selectRaw('phone, MAX(created_at) as t')->groupBy('phone')->pluck('t', 'phone');
        $reads = WhatsappConversation::pluck('agent_read_at', 'phone');
        $unread = 0;
        foreach ($lastInbound as $phone => $t) {
            $r = $reads[$phone] ?? null;
            if (strtotime($t) > ($r ? strtotime($r) : 0)) {
                $unread++;
            }
        }

        // Campaigns
        $campaignsByStatus = WhatsappCampaign::selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $recentCampaigns = WhatsappCampaign::with('group')->latest()->limit(5)->get()->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'group' => $c->group?->name,
            'total' => $c->total_recipients, 'sent' => $c->sent_count, 'created_at' => $c->created_at->toIso8601String(),
        ]);

        return response()->json([
            'connected' => $this->wa->isConfigured(),
            'messages' => [
                'sent' => (clone $out)->count(),
                'received' => (clone $in)->count(),
                'sent_today' => (clone $out)->whereDate('created_at', $today)->count(),
                'received_today' => (clone $in)->whereDate('created_at', $today)->count(),
                'delivered' => $delivered,
                'failed' => $failed,
            ],
            'conversations' => [
                'total' => WhatsappConversation::count(),
                'active' => WhatsappConversation::where('last_activity_at', '>=', now()->subDays(7))->count(),
                'unread' => $unread,
            ],
            'audience' => [
                'groups' => WhatsappGroup::count(),
                'contacts' => (int) WhatsappGroupMember::distinct()->count('phone'),
                'templates' => WhatsappTemplate::count(),
            ],
            'campaigns' => [
                'total' => WhatsappCampaign::count(),
                'sent' => (int) ($campaignsByStatus['sent'] ?? 0),
                'draft' => (int) ($campaignsByStatus['draft'] ?? 0),
                'scheduled' => (int) ($campaignsByStatus['scheduled'] ?? 0),
                'reached' => (int) WhatsappCampaign::sum('sent_count'),
            ],
            'series' => $series,
            'recent_campaigns' => $recentCampaigns,
        ]);
    }
}
