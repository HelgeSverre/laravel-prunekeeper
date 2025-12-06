<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Event dispatched when archive operation fails.
 */
class ArchiveFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Model $model,
        public readonly Throwable $exception
    ) {}

    /**
     * Get the model class name.
     */
    public function modelClass(): string
    {
        return $this->model::class;
    }
}
