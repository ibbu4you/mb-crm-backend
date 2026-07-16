<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;

/**
 * Free Spotlight leads captured before the meta fix stored their answers as a
 * flat array, which LeadDetails::fromMeta() cannot read (it only looks at
 * meta['form']) — so the drawer's "Additional details" panel rendered empty even
 * though the data was there. Re-nest them under `form` with the display labels.
 *
 * Idempotent: leads already carrying a `form` key are skipped.
 */
class BackfillSpotlightMeta extends Command
{
    protected $signature = 'spotlight:backfill-meta {--dry-run : Report what would change without saving}';

    protected $description = 'Re-nest legacy Free Spotlight lead meta so the lead drawer can render it.';

    /** old flat key => label shown to the team */
    private const MAP = [
        'position' => 'Position',
        'location' => 'Location (City, State)',
        'story' => 'Tell Us About Your Business / Story',
        'unique_story' => 'What Makes Your Story Unique or Inspiring?',
        'links' => 'Website / Social Media Links',
        'interview_mode' => 'Preferred Interview Mode',
        'language' => 'Preferred Language',
        'preferred_time' => 'Preferred Time Slot',
        'comments' => 'Comments',
        'referral_name' => 'Referral Name',
        'attachment_url' => 'Attachment',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $fixed = 0;
        $skipped = 0;

        Lead::whereNotNull('meta')->cursor()->each(function (Lead $lead) use (&$fixed, &$skipped, $dry) {
            $meta = $lead->meta ?? [];

            if (($meta['campaign'] ?? null) !== 'free_spotlight') {
                return;
            }
            if (isset($meta['form'])) {   // already in the new shape
                $skipped++;

                return;
            }

            $form = [];
            foreach (self::MAP as $key => $label) {
                if (! empty($meta[$key])) {
                    $form[$label] = $meta[$key];
                }
            }
            if (! empty($meta['consent_coverage'])) {
                $form['Consent — article coverage'] = 'Yes';
            }
            if (! empty($meta['consent_contact'])) {
                $form['Consent — editorial contact'] = 'Yes';
            }
            if (! $form) {
                return;
            }

            $this->line("  #{$lead->id} {$lead->contact?->business_name} -> ".count($form).' rows');
            if (! $dry) {
                $lead->meta = ['campaign' => 'free_spotlight', 'form' => $form];
                $lead->save();
            }
            $fixed++;
        });

        $this->info(($dry ? '[dry-run] would backfill ' : 'Backfilled ')."{$fixed} lead(s); {$skipped} already current.");

        return self::SUCCESS;
    }
}
