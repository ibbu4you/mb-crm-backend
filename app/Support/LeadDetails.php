<?php

namespace App\Support;

/**
 * Turns a WhatsApp lead's raw `meta` (Elementor form capture) into an ordered,
 * human-readable list of {label, value, link} rows — the "Additional Details"
 * panel from the legacy MB Leads admin.
 *
 * Most leads already store readable keys (e.g. "Location_(City,_State)"), so the
 * default is simply underscore → space. A small map covers one older form
 * variant that used opaque field hashes.
 */
class LeadDetails
{
    /** Older Elementor form variant → readable labels. */
    private const HASH_LABELS = [
        'No_Label_field_f4cd830' => 'Position',
        'No_Label_field_e8cc1bb' => 'Category',
        'No_Label_field_12c574c' => 'Location (City, State)',
        'No_Label_field_9c91c7d' => 'Tell Us About Your Business / Story',
        'No_Label_field_dc3773e' => 'What Makes Your Story Unique or Inspiring?',
        'No_Label_field_542f762' => 'Website / Social Media Links',
        'No_Label_field_017bef6' => 'Comments',
    ];

    /** Internal keys never worth showing. */
    private const SKIP = ['form_id', 'form_name'];

    public static function fromMeta(?array $meta): array
    {
        if (! $meta) {
            return [];
        }

        $out = [];

        if (! empty($meta['wa_lead_type'])) {
            $out[] = ['label' => 'Enquiry type', 'value' => (string) $meta['wa_lead_type'], 'link' => false];
        }

        $form = $meta['form'] ?? null;
        if (is_array($form)) {
            foreach ($form as $key => $value) {
                if (in_array($key, self::SKIP, true) || $value === null || $value === '' || $value === []) {
                    continue;
                }
                $val = $value === 'on' ? 'Yes' : (is_scalar($value) ? (string) $value : json_encode($value));
                if (trim($val) === '') {
                    continue;
                }
                $out[] = ['label' => self::label($key), 'value' => $val, 'link' => self::isUrl($val)];
            }
        }

        return $out;
    }

    private static function label(string $key): string
    {
        return self::HASH_LABELS[$key] ?? trim(str_replace('_', ' ', $key));
    }

    private static function isUrl(string $value): bool
    {
        return (bool) preg_match('#^https?://#i', trim($value));
    }
}
