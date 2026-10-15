<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentEmbedder;
use App\Support\EmbedEligibility;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Slice 12c. Embeds one document through DocumentEmbedder — the same call
 * `chunks:embed --document=<uuid>` makes. Never selects the global set.
 *
 * Lock releases are not failures: $tries is left unset and retryUntil()
 * allows releases for about two hours, while $maxExceptions = 1 fails the
 * job on the first real exception so it is not run again. uniqueFor expires
 * the per-document lock so a worker killed mid-embed cannot block a retry
 * forever.
 */
class EmbedDocument implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $maxExceptions = 1;

    public int $uniqueFor = 1800;

    public function __construct(public int $documentId) {}

    public function uniqueId(): string
    {
        return (string) $this->documentId;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('embed-documents'))->releaseAfter(60)->expireAfter(1200),
        ];
    }

    public function handle(DocumentEmbedder $embedder, EmbedEligibility $eligibility): void
    {
        $document = Document::find($this->documentId);
        if ($document === null) {
            return;
        }

        if (! $eligibility->eligible($document)) {
            $this->clearPending($document);

            return;
        }

        try {
            $result = $embedder->embed($document);
            if ($result === null) {
                $this->clearPending($document);

                return;
            }

            $document->update([
                'embed_state' => 'searchable',
                'embed_error_code' => null,
                'embed_state_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->recordFailure($document, $e);
            $this->releaseUniqueLock();

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $document = Document::find($this->documentId);
        if ($document === null || $document->embed_state === 'failed' || $document->embed_state === 'searchable') {
            return;
        }

        $this->recordFailure($document, $e);
    }

    public static function errorCode(Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'curl error 28')) {
            return 'timed_out';
        }

        if ($e instanceof ConnectionException || str_contains($message, 'connection') || str_contains($message, 'curl error 7')) {
            return 'service_unreachable';
        }

        return 'failed';
    }

    private function clearPending(Document $document): void
    {
        if ($document->embed_state !== 'pending') {
            return;
        }

        $document->update([
            'embed_state' => null,
            'embed_error_code' => null,
            'embed_state_at' => null,
        ]);
    }

    private function recordFailure(Document $document, Throwable $e): void
    {
        Log::error('embed document failed', [
            'document_id' => $document->id,
            'uuid' => $document->uuid,
            'exception' => $e,
        ]);

        $document->update([
            'embed_state' => 'failed',
            'embed_error_code' => self::errorCode($e),
            'embed_state_at' => now(),
        ]);
    }

    private function releaseUniqueLock(): void
    {
        (new UniqueLock(app(CacheRepository::class)))->release($this);
    }
}
