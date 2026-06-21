<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only provenance log. One row per facet decision, recording who set it,
 * how (filename_parse / ai_agent / admin_manual / system), when, and with what
 * confidence. Never updated or deleted.
 */
class TagEvent extends Model
{
    protected $fillable = [
        'entity_type', 'entity_id', 'facet', 'old_value', 'new_value',
        'source', 'actor_id', 'confidence', 'note',
    ];

    protected $casts = [
        'confidence' => 'float',
    ];
}
