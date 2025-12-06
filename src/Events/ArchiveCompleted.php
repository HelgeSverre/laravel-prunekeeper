<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Events;

use HelgeSverre\Prunekeeper\Support\ArchiveResult;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched after archive operation completes successfully.
 */
class ArchiveCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Model $model,
        public readonly ArchiveResult $result
    ) {}

    /**
     * Get the model class name.
     */
    public function modelClass(): string
    {
        return $this->model::class;
    }
}
