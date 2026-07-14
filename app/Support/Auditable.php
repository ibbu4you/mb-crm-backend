<?php

namespace App\Support;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Drop-in audit logging for a business model. Records create / update / delete
 * events — logging only the fields that actually changed — into the activity_log
 * table, capturing the acting user as the causer automatically. Surfaced in the
 * Activity Log viewer (Admin → Activity Log).
 */
trait Auditable
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
