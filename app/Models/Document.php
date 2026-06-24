<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Document extends Model
{
    protected $fillable = [
        'uuid', 'title', 'source_filename', 'storage_path', 'content_hash',
        'convenio_id', 'document_type_id', 'validity_start', 'validity_end',
        'retrieval_status', 'authority_level', 'predecessor_document_id',
        'language', 'tagging_status', 'tagging_confidence',
        'ingested_at', 'ingested_by',
    ];

    protected $casts = [
        'validity_start' => 'date',
        'validity_end' => 'date',
        'tagging_confidence' => 'float',
        'ingested_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Document $document) {
            if (empty($document->uuid)) {
                $document->uuid = (string) Str::uuid();
            }
        });
    }

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(DocumentPage::class)->orderBy('page_number');
    }

    public function reviewTasks(): HasMany
    {
        return $this->hasMany(DocumentReviewTask::class);
    }

    /** Version lineage: the document this one supersedes (e.g. 2020–2023 → 2024–2027). */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'predecessor_document_id');
    }

    /** The inverse of {@see predecessor()} — documents that name this one as their predecessor. */
    public function successors(): HasMany
    {
        return $this->hasMany(Document::class, 'predecessor_document_id');
    }

    /** Descriptive topics with their provenance (Sprint 3 is the first writer). */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'document_topics')
            ->withPivot(['source', 'confidence', 'verified_by', 'verified_at'])
            ->withTimestamps();
    }

    public function documentTopics(): HasMany
    {
        return $this->hasMany(DocumentTopic::class);
    }
}
