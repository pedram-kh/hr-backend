<?php

namespace App\Support;

use App\Models\Document;

/**
 * Slice 12c. Whether confirming (or a lifecycle flip) should queue an embed
 * of this one document. Narrower than `chunks:embed`: historical and draft
 * are skipped, and an official convenio with no convenio is skipped because
 * chat cannot retrieve that chunk. The operator command is unchanged.
 */
class EmbedEligibility
{
    public const PROSE_TYPES = ['convenio_text', 'national_law', 'partial_agreement', 'internal_hr_ruling'];

    public function eligible(Document $document): bool
    {
        $document->loadMissing(['documentType', 'pages']);

        if (! in_array($document->documentType?->code, self::PROSE_TYPES, true)) {
            return false;
        }

        if ($document->retrieval_status !== 'active') {
            return false;
        }

        if ($document->tagging_status === 'under_review') {
            return false;
        }

        $hasText = $document->pages->contains(fn ($page) => trim((string) $page->text) !== '');
        if (! $hasText) {
            return false;
        }

        // D1. National law is retrieved with a null convenio. An official
        // convenio chunk with a null convenio matches neither retrieval arm.
        if ($document->authority_level === 'official_convenio' && $document->convenio_id === null) {
            return false;
        }

        return true;
    }
}
