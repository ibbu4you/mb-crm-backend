<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\CompanyDocument;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\LeadType;
use App\Models\PortfolioImage;
use App\Models\PortfolioItem;
use App\Models\Target;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * One-shot migration of the three legacy systems into the unified CRM:
 *   - legacy_content_hub  (article/content workflow)
 *   - legacy_mb_sales     (field sales)
 *   - legacy_mb_leads     (WhatsApp lead bot)
 *
 * Idempotent: truncates the business tables and re-imports on every run.
 * Users are upserted by email (never truncated) so the admin survives.
 */
class ImportLegacyData extends Command
{
    protected $signature = 'import:legacy';

    protected $description = 'Import the 3 legacy systems (content hub, mb sales, whatsapp leads) into the unified CRM';

    /** old id -> new user id */
    private array $userByChId = [];
    private array $userBySalesId = [];

    /** dedup registries */
    private array $contactByEmail = [];
    private array $contactByPhone = [];

    /** old id -> new id */
    private array $chClientToContact = [];
    private array $salesClientToLead = [];
    private array $salesVisitToNew = [];
    private array $waLeadToNew = [];
    private array $articleMap = [];
    private array $viralPkgMap = [];
    private array $viralDelivMap = [];
    private array $supportTicketMap = [];

    private ?int $adminId = null;
    private ?int $freeSpotlightTypeId = null;

    public function handle(): int
    {
        $this->registerConnections();

        $this->info('Truncating business tables…');
        $this->truncateBusiness();

        $this->info('Seeding lead types…');
        $this->seedLeadTypes();

        $this->info('Importing users…');
        $this->importUsers();

        $this->info('Importing content-hub clients → contacts…');
        $this->importContentClients();

        $this->info('Importing articles…');
        $this->importArticles();

        $this->info('Importing field-sales clients → contacts + leads…');
        $this->importSalesClients();

        $this->info('Importing visits / follow-ups / deals / targets…');
        $this->importVisits();
        $this->importFollowUps();
        $this->importDeals();
        $this->importTargets();

        $this->info('Importing WhatsApp leads → contacts + leads…');
        $this->importWhatsappLeads();
        $this->importWhatsappNotes();

        $this->info('Importing article stage history…');
        $this->importStageHistories();

        $this->info('Importing viral packages…');
        $this->importViralPackages();
        $this->importViralDeliverables();
        $this->importViralHistory();

        $this->info('Importing support tickets…');
        $this->importSupport();

        $this->info('Importing portfolio + documents…');
        $this->importPortfolio();

        $this->newLine();
        $this->summary();

        return self::SUCCESS;
    }

    /* ------------------------------------------------------------------ */

    private function registerConnections(): void
    {
        $base = config('database.connections.mysql');
        foreach (['legacy_content_hub', 'legacy_mb_sales', 'legacy_mb_leads'] as $name) {
            config(["database.connections.$name" => array_merge($base, ['database' => $name])]);
        }
    }

    private function truncateBusiness(): void
    {
        $tables = [
            'lead_notes', 'deals', 'follow_ups', 'visits', 'targets',
            'viral_package_history', 'viral_package_deliverables', 'viral_packages',
            'support_ticket_replies', 'support_attachments', 'support_tickets',
            'stage_histories', 'article_comments', 'article_assets', 'articles',
            'leads', 'contacts',
            'portfolio_images', 'portfolio_items', 'company_documents',
        ];
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $t) {
            if (DB::getSchemaBuilder()->hasTable($t)) {
                DB::table($t)->truncate();
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /* ---------------------------- helpers ----------------------------- */

    private function cleanEmail(?string $email): ?string
    {
        $e = strtolower(trim((string) $email));
        $e = preg_replace('/^deleted_\d+_/', '', $e);
        return $e === '' ? null : $e;
    }

    private function digits(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone);
    }

    /** Save a model preserving the original timestamps. */
    private function stamp($model, $created = null, $updated = null)
    {
        $model->timestamps = false;
        $model->created_at = $created ? Carbon::parse($created) : Carbon::now();
        $model->updated_at = $updated ? Carbon::parse($updated) : $model->created_at;
        $model->save();
        return $model;
    }

    private function resolveContact(array $a): Contact
    {
        $email = $this->cleanEmail($a['email'] ?? null);
        $digits = $this->digits($a['phone'] ?? null);
        $hasPhone = strlen($digits) >= 7;

        if ($email && isset($this->contactByEmail[$email])) {
            return Contact::find($this->contactByEmail[$email]);
        }
        if ($hasPhone && isset($this->contactByPhone[$digits])) {
            return Contact::find($this->contactByPhone[$digits]);
        }

        $name = $a['business_name'] ?: ($a['contact_person'] ?? null) ?: ($email ?: ('Contact ' . $digits));

        $c = new Contact();
        $c->business_name = mb_substr((string) $name, 0, 191);
        $c->contact_person = $a['contact_person'] ?? null;
        $c->email = $email;
        $c->phone = $a['phone'] ?? null;
        $c->industry = $a['industry'] ?? null;
        $c->city = $a['city'] ?? null;
        $c->address = $a['address'] ?? null;
        $c->source = $a['source'] ?? 'manual';
        $c->owner_id = $a['owner_id'] ?? null;
        $c->created_by = $a['created_by'] ?? null;
        $c->notes = $a['notes'] ?? null;
        $c->meta = $a['meta'] ?? null;
        $this->stamp($c, $a['created_at'] ?? null, $a['updated_at'] ?? null);

        if ($email) {
            $this->contactByEmail[$email] = $c->id;
        }
        if ($hasPhone) {
            $this->contactByPhone[$digits] = $c->id;
        }
        return $c;
    }

    /* -------------------------- lead types ---------------------------- */

    private function seedLeadTypes(): void
    {
        $types = [
            ['name' => 'Free Spotlight', 'color' => '#60A5FA', 'sort_order' => 1],
            ['name' => 'Go Viral', 'color' => '#4F46E5', 'sort_order' => 2],
            ['name' => 'Branding Consultation', 'color' => '#16A34A', 'sort_order' => 3],
            ['name' => 'Automation', 'color' => '#D97706', 'sort_order' => 4],
            ['name' => 'Package Enquiry', 'color' => '#E5484D', 'sort_order' => 5],
        ];
        foreach ($types as $t) {
            LeadType::updateOrCreate(['name' => $t['name']], $t + ['is_active' => true]);
        }
        // All WhatsApp bot leads came through the free interview/spotlight funnel.
        $this->freeSpotlightTypeId = LeadType::where('name', 'Free Spotlight')->value('id');
    }

    /* ----------------------------- users ------------------------------ */

    private function mapRole(array $roles): string
    {
        $r = array_map('strtolower', $roles);
        if (in_array('admin', $r, true)) {
            return 'Admin';
        }
        if (array_intersect(['sales', 'salesperson'], $r)) {
            return 'Salesperson';
        }
        if (in_array('tech_team', $r, true)) {
            return 'Tech Writer';
        }
        return 'Salesperson';
    }

    private function importUsers(): void
    {
        $this->adminId = User::where('email', 'admin@malayznbeat.com')->value('id');

        $rows = [];
        foreach (DB::connection('legacy_content_hub')->table('users')->get() as $u) {
            $rows[] = ['src' => 'ch', 'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                'phone' => $u->phone, 'role' => $u->role, 'password' => $u->password,
                'is_active' => $u->is_active, 'created_at' => $u->created_at];
        }
        foreach (DB::connection('legacy_mb_sales')->table('users')->get() as $u) {
            $rows[] = ['src' => 'sales', 'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                'phone' => $u->phone, 'role' => $u->role, 'password' => $u->password,
                'is_active' => $u->is_active, 'created_at' => $u->created_at];
        }

        // group by normalized email
        $groups = [];
        foreach ($rows as $r) {
            $key = $this->cleanEmail($r['email']) ?? ('noemail:' . $r['src'] . ':' . $r['id']);
            $groups[$key][] = $r;
        }

        foreach ($groups as $email => $members) {
            $isPlaceholder = str_starts_with($email, 'noemail:');
            $roles = array_column($members, 'role');
            $roleName = $this->mapRole($roles);
            $active = (bool) max(array_column($members, 'is_active'));

            $salesM = collect($members)->firstWhere('src', 'sales');
            $chM = collect($members)->firstWhere('src', 'ch');
            $name = $salesM['name'] ?? $chM['name'];
            $phone = trim((string) ($salesM['phone'] ?? $chM['phone'] ?? '')) ?: null;
            $hash = $salesM['password'] ?? $chM['password'];
            $created = $chM['created_at'] ?? ($salesM['created_at'] ?? null);

            $isAdmin = $email === 'admin@malayznbeat.com';

            if ($isAdmin && $this->adminId) {
                // keep the existing Administrator account untouched
                foreach ($members as $m) {
                    $this->registerUserId($m, $this->adminId);
                }
                continue;
            }

            $user = $isPlaceholder ? null : User::where('email', $email)->first();
            if (! $user) {
                $user = new User();
                $user->email = $isPlaceholder ? null : $email;
            }
            $user->name = $name;
            $user->phone = $phone;
            $user->is_active = $active;
            $user->email_verified_at = Carbon::now();
            $user->password = 'placeholder-will-be-overwritten';
            $user->timestamps = false;
            $user->created_at = $created ? Carbon::parse($created) : Carbon::now();
            $user->updated_at = Carbon::now();
            $user->save();

            // store the ORIGINAL bcrypt hash verbatim (bypass the "hashed" cast)
            if ($hash) {
                DB::table('users')->where('id', $user->id)->update(['password' => $hash]);
            }

            $user->syncRoles([$roleName]);

            foreach ($members as $m) {
                $this->registerUserId($m, $user->id);
            }
        }
    }

    private function registerUserId(array $member, int $newId): void
    {
        if ($member['src'] === 'ch') {
            $this->userByChId[$member['id']] = $newId;
        } else {
            $this->userBySalesId[$member['id']] = $newId;
        }
    }

    /* --------------------------- content hub -------------------------- */

    private function importContentClients(): void
    {
        foreach (DB::connection('legacy_content_hub')->table('clients')->get() as $c) {
            $contact = $this->resolveContact([
                'business_name' => $c->company ?: $c->name,
                'contact_person' => $c->name,
                'email' => $c->contact_email,
                'phone' => $c->contact_phone,
                'source' => 'manual',
                'owner_id' => $this->userByChId[$c->created_by] ?? null,
                'created_by' => $this->userByChId[$c->created_by] ?? null,
                'notes' => $c->notes,
                'created_at' => $c->created_at,
                'updated_at' => $c->updated_at,
            ]);
            $this->chClientToContact[$c->id] = $contact->id;
        }
    }

    private function importArticles(): void
    {
        $valid = ['inbox', 'assigned', 'in_progress', 'internal_review', 'client_approval', 'revisions', 'approved', 'published'];
        foreach (DB::connection('legacy_content_hub')->table('articles')->get() as $a) {
            $art = new Article();
            $art->article_code = $a->article_code;
            $art->title = $a->title;
            $art->client_id = $this->chClientToContact[$a->client_id] ?? null;
            $art->sales_rep_id = $this->userByChId[$a->sales_rep_id] ?? $this->adminId;
            $art->tech_writer_id = $this->userByChId[$a->tech_writer_id] ?? null;
            $art->current_stage = in_array($a->current_stage, $valid, true) ? $a->current_stage : 'inbox';
            $art->priority = in_array($a->priority, ['low', 'medium', 'high'], true) ? $a->priority : 'medium';
            $art->deadline = $a->deadline;
            $art->word_count_target = $a->word_count_target;
            $art->published_url = $a->published_url;
            $art->published_at = $a->published_at;
            $art->submitted_at = $a->submitted_at;
            $art->stage_entered_at = $a->stage_entered_at;
            $art->notes = $a->notes;
            $this->stamp($art, $a->created_at, $a->updated_at);
            $this->articleMap[$a->id] = $art->id;
        }
    }

    /* ------------------- content: history/viral/support --------------- */

    private function ts($v)
    {
        return $v ? Carbon::parse($v) : Carbon::now();
    }

    private function importStageHistories(): void
    {
        foreach (DB::connection('legacy_content_hub')->table('stage_histories')->orderBy('id')->get() as $h) {
            $articleId = $this->articleMap[$h->article_id] ?? null;
            if (! $articleId) {
                continue;
            }
            DB::table('stage_histories')->insert([
                'article_id' => $articleId,
                'from_stage' => $h->from_stage,
                'to_stage' => $h->to_stage,
                'changed_by' => $this->userByChId[$h->changed_by] ?? null,
                'notes' => $h->notes ? mb_substr($h->notes, 0, 255) : null,
                'changed_at' => $this->ts($h->changed_at),
                'created_at' => $this->ts($h->created_at),
                'updated_at' => $this->ts($h->updated_at),
            ]);
        }
    }

    private function importViralPackages(): void
    {
        $n = 0;
        foreach (DB::connection('legacy_content_hub')->table('viral_packages')->orderBy('id')->get() as $p) {
            $contactId = $this->chClientToContact[$p->client_id] ?? null;
            $business = $contactId ? DB::table('contacts')->where('id', $contactId)->value('business_name') : null;
            $code = 'VP-'.str_pad((string) (++$n), 4, '0', STR_PAD_LEFT);

            $newId = DB::table('viral_packages')->insertGetId([
                'code' => $code,
                'contact_id' => $contactId,
                'sales_rep_id' => $this->userByChId[$p->sales_rep_id] ?? $this->adminId,
                'tech_team_id' => $this->userByChId[$p->tech_team_id] ?? null,
                'title' => trim(($business ?: 'Client').' — Viral Package'),
                'status' => mb_substr($p->status, 0, 16),
                'completed_at' => $p->completed_at ? $this->ts($p->completed_at) : null,
                'notes' => null,
                'created_at' => $this->ts($p->created_at),
                'updated_at' => $this->ts($p->updated_at),
            ]);
            $this->viralPkgMap[$p->id] = $newId;
        }
    }

    private function importViralDeliverables(): void
    {
        foreach (DB::connection('legacy_content_hub')->table('viral_package_deliverables')->orderBy('id')->get() as $d) {
            $pkgId = $this->viralPkgMap[$d->viral_package_id] ?? null;
            if (! $pkgId) {
                continue;
            }
            $newId = DB::table('viral_package_deliverables')->insertGetId([
                'viral_package_id' => $pkgId,
                'kind' => mb_substr($d->kind, 0, 20),
                'slot_number' => $d->slot_number,
                'title' => $d->title,
                'stage' => mb_substr($d->stage, 0, 16),
                'assigned_to' => $this->userByChId[$d->assigned_to] ?? null,
                'file_path' => null, // legacy stored Google Drive file ids, not local paths
                'filename' => $d->drive_filename,
                'mime_type' => $d->mime_type,
                'file_size' => $d->file_size,
                'caption' => $d->caption,
                'hashtags' => $d->hashtags,
                'target_audience' => $d->target_audience,
                'landing_page_url' => $d->landing_page_url,
                'submitted_at' => $d->submitted_at ? $this->ts($d->submitted_at) : null,
                'approved_at' => $d->approved_at ? $this->ts($d->approved_at) : null,
                'created_at' => $this->ts($d->created_at),
                'updated_at' => $this->ts($d->updated_at),
            ]);
            $this->viralDelivMap[$d->id] = $newId;
        }
    }

    private function importViralHistory(): void
    {
        foreach (DB::connection('legacy_content_hub')->table('viral_package_history')->orderBy('id')->get() as $h) {
            $delivId = $this->viralDelivMap[$h->deliverable_id] ?? null;
            if (! $delivId) {
                continue;
            }
            DB::table('viral_package_history')->insert([
                'deliverable_id' => $delivId,
                'from_stage' => $h->from_stage ? mb_substr($h->from_stage, 0, 16) : null,
                'to_stage' => mb_substr($h->to_stage, 0, 16),
                'changed_by' => $this->userByChId[$h->changed_by] ?? null,
                'notes' => $h->notes ? mb_substr($h->notes, 0, 255) : null,
                'changed_at' => $this->ts($h->changed_at),
                'created_at' => $this->ts($h->created_at),
                'updated_at' => $this->ts($h->updated_at),
            ]);
        }
    }

    private function importSupport(): void
    {
        foreach (DB::connection('legacy_content_hub')->table('support_tickets')->orderBy('id')->get() as $t) {
            $newId = DB::table('support_tickets')->insertGetId([
                'code' => $t->code,
                'subject' => $t->subject,
                'description' => $t->description,
                'priority' => $t->priority,
                'status' => $t->status,
                'reporter_id' => $this->userByChId[$t->reporter_id] ?? $this->adminId,
                'assignee_id' => $this->userByChId[$t->assignee_id] ?? null,
                'last_activity_at' => $t->last_activity_at ? $this->ts($t->last_activity_at) : null,
                'resolved_at' => $t->resolved_at ? $this->ts($t->resolved_at) : null,
                'closed_at' => $t->closed_at ? $this->ts($t->closed_at) : null,
                'created_at' => $this->ts($t->created_at),
                'updated_at' => $this->ts($t->updated_at),
            ]);
            $this->supportTicketMap[$t->id] = $newId;
        }

        foreach (DB::connection('legacy_content_hub')->table('support_ticket_replies')->orderBy('id')->get() as $r) {
            $ticketId = $this->supportTicketMap[$r->ticket_id] ?? null;
            if (! $ticketId) {
                continue;
            }
            DB::table('support_ticket_replies')->insert([
                'ticket_id' => $ticketId,
                'user_id' => $this->userByChId[$r->user_id] ?? $this->adminId,
                'body' => $r->body,
                'is_system' => $r->is_system,
                'created_at' => $this->ts($r->created_at),
                'updated_at' => $this->ts($r->updated_at),
            ]);
        }
    }

    /* --------------------------- field sales -------------------------- */

    private function importSalesClients(): void
    {
        $stages = ['intake', 'cold', 'warm', 'qualified', 'opportunity', 'proposal', 'won', 'lost'];
        foreach (DB::connection('legacy_mb_sales')->table('clients')->get() as $c) {
            $ownerId = $this->userBySalesId[$c->assigned_to] ?? null;
            $industry = ($c->category !== null && trim($c->category) !== '') ? trim($c->category) : null;

            $contact = $this->resolveContact([
                'business_name' => $c->business_name,
                'contact_person' => $c->contact_person,
                'phone' => $c->contact_phone,
                'address' => $c->address,
                'industry' => $industry,
                'source' => 'field',
                'owner_id' => $ownerId,
                'created_by' => $this->userBySalesId[$c->created_by] ?? null,
                'notes' => $c->notes,
                'created_at' => $c->created_at,
                'updated_at' => $c->updated_at,
            ]);

            $rev = DB::connection('legacy_mb_sales')->table('visits')->where('client_id', $c->id)->max('revenue_potential') ?? 0;
            $stage = in_array($c->pipeline_stage, $stages, true) ? $c->pipeline_stage : 'cold';
            $status = $stage === 'won' ? 'won' : ($stage === 'lost' ? 'lost' : 'active');

            $lead = new Lead();
            $lead->contact_id = $contact->id;
            $lead->lead_type_id = null;
            $lead->title = null;
            $lead->pipeline_stage = $stage;
            $lead->status = $status;
            $lead->source = 'field';
            $lead->owner_id = $ownerId;
            $lead->revenue_potential = $rev;
            $lead->last_activity_at = $c->last_visit_at;
            $lead->notes = $c->notes;
            $lead->meta = ['legacy' => 'mb_sales', 'category' => $c->category];
            $this->stamp($lead, $c->created_at, $c->updated_at);

            $this->salesClientToLead[$c->id] = $lead->id;
        }
    }

    private function importVisits(): void
    {
        foreach (DB::connection('legacy_mb_sales')->table('visits')->get() as $v) {
            $leadId = $this->salesClientToLead[$v->client_id] ?? null;
            if (! $leadId) {
                continue;
            }
            $visit = new Visit();
            $visit->lead_id = $leadId;
            $visit->user_id = $this->userBySalesId[$v->user_id] ?? $this->adminId;
            $visit->visit_date = $v->visit_date;
            $visit->visit_level = $v->visit_level;
            $visit->person_met = $v->person_met;
            $visit->contact_phone = $v->contact_phone;
            $visit->decision_maker_met = (bool) $v->decision_maker_met;
            $visit->interested = (bool) $v->interested;
            $visit->follow_up_done = (bool) $v->follow_up_done;
            $visit->revenue_potential = $v->revenue_potential;
            $visit->notes = $v->notes;
            $visit->photo_path = $v->photo_path;
            $this->stamp($visit, $v->created_at, $v->updated_at);
            $this->salesVisitToNew[$v->id] = $visit->id;
        }
    }

    private function importFollowUps(): void
    {
        foreach (DB::connection('legacy_mb_sales')->table('follow_ups')->get() as $f) {
            $leadId = $this->salesClientToLead[$f->client_id] ?? null;
            if (! $leadId) {
                continue;
            }
            $fu = new FollowUp();
            $fu->lead_id = $leadId;
            $fu->visit_id = $f->visit_id ? ($this->salesVisitToNew[$f->visit_id] ?? null) : null;
            $fu->user_id = $this->userBySalesId[$f->user_id] ?? $this->adminId;
            $fu->due_date = $f->due_date;
            $fu->note = $f->note;
            $fu->status = in_array($f->status, ['pending', 'done'], true) ? $f->status : 'pending';
            $fu->completed_at = $f->completed_at;
            $this->stamp($fu, $f->created_at, $f->updated_at);
        }
    }

    private function importDeals(): void
    {
        foreach (DB::connection('legacy_mb_sales')->table('deals')->get() as $d) {
            $leadId = $this->salesClientToLead[$d->client_id] ?? null;
            if (! $leadId) {
                continue;
            }
            $deal = new Deal();
            $deal->lead_id = $leadId;
            $deal->user_id = $this->userBySalesId[$d->user_id] ?? $this->adminId;
            $deal->outcome = in_array($d->outcome, ['won', 'lost'], true) ? $d->outcome : 'won';
            $deal->actual_revenue = $d->actual_revenue;
            $deal->notes = $d->notes;
            $deal->closed_at = $d->closed_at;
            $this->stamp($deal, $d->created_at, $d->updated_at);
        }
    }

    private function importTargets(): void
    {
        foreach (DB::connection('legacy_mb_sales')->table('targets')->get() as $t) {
            $userId = $this->userBySalesId[$t->user_id] ?? null;
            if (! $userId) {
                continue;
            }
            $tg = new Target();
            $tg->user_id = $userId;
            $tg->period = $t->period;
            $tg->visits_target = $t->visits_target;
            $tg->revenue_target = $t->revenue_target;
            $this->stamp($tg, $t->created_at, $t->updated_at);
        }
    }

    /* -------------------------- whatsapp leads ------------------------ */

    private function importWhatsappLeads(): void
    {
        $map = [
            'NEW' => ['intake', 'active'],
            'CONTACTED' => ['warm', 'active'],
            'CLOSED' => ['cold', 'dormant'],
            'NOT INTERESTED' => ['lost', 'lost'],
        ];
        foreach (DB::connection('legacy_mb_leads')->table('whatsapp_leads')->get() as $l) {
            $meta = json_decode($l->meta_data ?? 'null', true);
            [$stage, $status] = $map[strtoupper(trim($l->status))] ?? ['intake', 'active'];

            $contact = $this->resolveContact([
                'business_name' => $l->business ?: ($l->name ?: ('WhatsApp ' . $l->phone)),
                'contact_person' => $l->name,
                'email' => $l->email,
                'phone' => $l->phone,
                'industry' => $l->industry,
                'city' => $l->city,
                'source' => 'whatsapp',
                'meta' => is_array($meta) ? ['wa_form' => $meta] : null,
                'created_at' => $l->created_at,
                'updated_at' => $l->updated_at,
            ]);

            $lead = new Lead();
            $lead->contact_id = $contact->id;
            $lead->lead_type_id = $this->freeSpotlightTypeId;
            $lead->title = null;
            $lead->pipeline_stage = $stage;
            $lead->status = $status;
            $lead->source = 'whatsapp';
            $lead->owner_id = null;
            $lead->revenue_potential = 0;
            $lead->last_activity_at = $l->updated_at;
            $lead->meta = [
                'wa_lead_type' => $l->lead_type,
                'wa_status' => $l->status,
                'payment_status' => $l->payment_status,
                'form' => is_array($meta) ? $meta : null,
            ];
            $this->stamp($lead, $l->created_at, $l->updated_at);
            $this->waLeadToNew[$l->id] = $lead->id;
        }
    }

    private function importWhatsappNotes(): void
    {
        foreach (DB::connection('legacy_mb_leads')->table('whatsapp_lead_notes')->get() as $n) {
            $leadId = $this->waLeadToNew[$n->whatsapp_lead_id] ?? null;
            if (! $leadId) {
                continue;
            }
            $ln = new LeadNote();
            $ln->lead_id = $leadId;
            $ln->user_id = $this->adminId;
            $ln->body = $n->note;
            $this->stamp($ln, $n->created_at, $n->updated_at);
        }
    }

    /* ---------------------------- portfolio --------------------------- */

    private function flattenCreds(?string $json): ?array
    {
        $d = json_decode($json ?? 'null', true);
        if (! is_array($d) || ! $d) {
            return null;
        }
        $out = [];
        foreach ($d as $i => $row) {
            if (is_array($row)) {
                $label = $row['label'] ?? ('Login ' . ($i + 1));
                $parts = array_filter([$row['username'] ?? null, $row['password'] ?? null], fn ($x) => $x !== null && $x !== '');
                $val = implode(' / ', $parts);
                if (! empty($row['url'])) {
                    $val = trim($val . ' · ' . $row['url'], ' ·');
                }
                if ($val === '') {
                    continue;
                }
                $key = $label;
                $n = 1;
                while (isset($out[$key])) {
                    $key = $label . ' (' . (++$n) . ')';
                }
                $out[$key] = $val;
            } else {
                $out[(string) $i] = (string) $row;
            }
        }
        return $out ?: null;
    }

    private function importPortfolio(): void
    {
        foreach (DB::connection('legacy_mb_sales')->table('portfolio_items')->get() as $p) {
            $pi = new PortfolioItem();
            $pi->type = in_array($p->type, ['website', 'video', 'graphic', 'automation', 'article'], true) ? $p->type : 'website';
            $pi->title = $p->title;
            $pi->url = $p->url;
            $pi->image_path = $p->image_path ?: $p->preview_image;
            $pi->description = $p->description;
            $pi->credentials = $this->flattenCreds($p->credentials);
            $pi->sort_order = $p->sort_order;
            $pi->is_active = (bool) $p->is_active;
            $pi->created_by = $this->userBySalesId[$p->uploaded_by] ?? $this->adminId;
            $this->stamp($pi, $p->created_at, $p->updated_at);

            foreach (DB::connection('legacy_mb_sales')->table('portfolio_images')->where('portfolio_item_id', $p->id)->get() as $img) {
                $pimg = new PortfolioImage();
                $pimg->portfolio_item_id = $pi->id;
                $pimg->image_path = $img->image_path;
                $pimg->sort_order = $img->sort_order;
                $this->stamp($pimg, $img->created_at, $img->updated_at);
            }
        }

        // company_documents (0 rows in legacy, but handle if present)
        if (DB::connection('legacy_mb_sales')->getSchemaBuilder()->hasTable('company_documents')) {
            foreach (DB::connection('legacy_mb_sales')->table('company_documents')->get() as $doc) {
                $cd = new CompanyDocument();
                $cd->title = $doc->title;
                $cd->category = 'other';
                $cd->file_path = $doc->file_path ?? '';
                $cd->original_name = $doc->original_name ?? null;
                $cd->size = $doc->size ?? null;
                $cd->is_active = (bool) ($doc->is_active ?? true);
                $cd->sort_order = $doc->sort_order ?? 0;
                $cd->uploaded_by = $this->adminId;
                $this->stamp($cd, $doc->created_at ?? null, $doc->updated_at ?? null);
            }
        }
    }

    /* ----------------------------- summary ---------------------------- */

    private function summary(): void
    {
        $rows = [
            ['users', User::count()],
            ['contacts', Contact::count()],
            ['leads', Lead::count()],
            ['lead_notes', LeadNote::count()],
            ['articles', Article::count()],
            ['stage_histories', DB::table('stage_histories')->count()],
            ['viral_packages', DB::table('viral_packages')->count()],
            ['viral_deliverables', DB::table('viral_package_deliverables')->count()],
            ['viral_history', DB::table('viral_package_history')->count()],
            ['support_tickets', DB::table('support_tickets')->count()],
            ['support_replies', DB::table('support_ticket_replies')->count()],
            ['visits', Visit::count()],
            ['follow_ups', FollowUp::count()],
            ['deals', Deal::count()],
            ['targets', Target::count()],
            ['portfolio_items', PortfolioItem::count()],
            ['portfolio_images', PortfolioImage::count()],
            ['company_documents', CompanyDocument::count()],
        ];
        $this->table(['table', 'rows'], $rows);
    }
}
