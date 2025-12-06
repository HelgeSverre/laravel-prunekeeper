<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when archive operation is skipped.
 */
class ArchiveSkipped
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public const REASON_DISABLED = 'archiving_disabled';

    public const REASON_NO_RECORDS = 'no_prunable_records';

    public const REASON_NO_PRUNABLE_METHOD = 'no_prunable_method';

    public const REASON_PRETEND_MODE = 'pretend_mode';

    public function __construct(
        public readonly Model $model,
        public readonly string $reason
    ) {}

    /**
     * Get the model class name.
     */
    public function modelClass(): string
    {
        return $this->model::class;
    }
}
