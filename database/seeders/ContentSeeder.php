<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticleType;
use App\Models\Contact;
use App\Models\User;
use App\Support\ArticleWorkflow as WF;
use Illuminate\Database\Seeder;

class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $types = ['Blog Post', 'SEO Article', 'Press Release', 'Product Description', 'Social Caption'];
        foreach ($types as $i => $name) {
            ArticleType::updateOrCreate(['slug' => \Illuminate\Support\Str::slug($name)], ['name' => $name, 'sort_order' => $i + 1]);
        }
        $typeIds = ArticleType::pluck('id')->all();

        $sales = User::where('email', 'sales@malayznbeat.com')->value('id')
            ?? User::where('email', 'admin@malayznbeat.com')->value('id');
        $writer = User::where('email', 'writer@malayznbeat.com')->value('id');
        $admin = User::where('email', 'admin@malayznbeat.com')->value('id');
        $contacts = Contact::pluck('id')->all();

        $samples = [
            ['5 Ways to Grow Your F&B Brand', WF::INBOX, 'high'],
            ['Ramadan Marketing Playbook', WF::ASSIGNED, 'medium'],
            ['How Automation Saves SMEs Time', WF::IN_PROGRESS, 'medium'],
            ['Case Study: Sunway Property Launch', WF::CLIENT_APPROVAL, 'high'],
            ['Top 10 Instagram Reels Ideas', WF::REVISIONS, 'low'],
            ['Branding for Malaysian Startups', WF::APPROVED, 'medium'],
            ['Why Every Business Needs a Blog', WF::PUBLISHED, 'low'],
            ['SEO Basics for Local Shops', WF::PUBLISHED, 'medium'],
        ];

        foreach ($samples as $i => [$title, $stage, $priority]) {
            $assigned = ! in_array($stage, [WF::INBOX], true);
            $article = Article::updateOrCreate(
                ['title' => $title],
                [
                    'article_code' => Article::nextCode(),
                    'client_id' => $contacts[$i % count($contacts)] ?? null,
                    'article_type_id' => $typeIds[$i % count($typeIds)],
                    'sales_rep_id' => $sales,
                    'tech_writer_id' => $assigned ? $writer : null,
                    'current_stage' => $stage,
                    'priority' => $priority,
                    'deadline' => now()->addDays(($i % 5) + 3),
                    'word_count_target' => [600, 800, 1000, 1200][$i % 4],
                    'notes' => 'Client wants a friendly, upbeat tone.',
                    'submitted_at' => now()->subDays(10 - $i),
                    'stage_entered_at' => now()->subDays(5 - ($i % 5)),
                    'published_url' => $stage === WF::PUBLISHED ? 'https://malayznbeat.com/blog/'.\Illuminate\Support\Str::slug($title) : null,
                    'published_at' => $stage === WF::PUBLISHED ? now()->subDays($i) : null,
                ],
            );

            // Minimal history so the timeline shows something.
            if ($article->history()->count() === 0) {
                $article->history()->create(['from_stage' => null, 'to_stage' => WF::INBOX, 'changed_by' => $sales, 'changed_at' => now()->subDays(10 - $i)]);
                if ($stage !== WF::INBOX) {
                    $article->history()->create(['from_stage' => WF::INBOX, 'to_stage' => $stage, 'changed_by' => $admin, 'changed_at' => now()->subDays(5 - ($i % 5))]);
                }
            }

            if ($i % 2 === 0) {
                $article->comments()->create(['user_id' => $sales, 'body' => 'Please emphasise the promotion in the intro.']);
            }
        }
    }
}
