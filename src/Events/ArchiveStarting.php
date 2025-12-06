<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched before archive operation begins.
 */
class ArchiveStarting
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Model $model,
        public readonly int $recordCount
    ) {}

    /**
     * Get the model class name.
     */
    public function modelClass(): string
    {
        return $this->model::class;
    }
}
