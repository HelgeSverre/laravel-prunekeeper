<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\Events\ArchiveFailed;
use HelgeSverre\Prunekeeper\Prunekeeper;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestPrunableModel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

it('reports a failure when a command before-archive callback throws', function () {
    Event::fake([ArchiveFailed::class]);
    Storage::fake('local');
    TestPrunableModel::create(['name' => 'Preserve me', 'created_at' => now()->subMonths(2)]);
    Prunekeeper::beforeArchiving(fn () => throw new RuntimeException('Before hook failed'));

    expect(fn () => $this->artisan('prunekeeper:archive', ['--model' => [TestPrunableModel::class]]))
        ->toThrow(RuntimeException::class, 'Before hook failed');

    Event::assertDispatched(ArchiveFailed::class);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
    expect(TestPrunableModel::count())->toBe(1);
});
