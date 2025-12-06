<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\ArchivePrunedRecords;
use HelgeSverre\Prunekeeper\Contracts\Exporter;
use HelgeSverre\Prunekeeper\Events\ArchiveCompleted;
use HelgeSverre\Prunekeeper\Events\ArchiveFailed;
use HelgeSverre\Prunekeeper\Events\ArchiveSkipped;
use HelgeSverre\Prunekeeper\Events\ArchiveStarting;
use HelgeSverre\Prunekeeper\Exporters\CsvExporter;
use HelgeSverre\Prunekeeper\Listeners\ArchiveBeforePruning;
use HelgeSverre\Prunekeeper\Prunekeeper;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestPrunableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Events\ModelPruningStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

describe('Listener Events', function () {
    it('dispatches ArchiveStarting event before archiving', function () {
        Event::fake([ArchiveStarting::class]);

        TestPrunableModel::create([
            'name' => 'Old Record',
            'created_at' => now()->subMonths(2),
        ]);

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([TestPrunableModel::class]);
        $listener->handle($event);

        Event::assertDispatched(ArchiveStarting::class, function ($event) {
            return $event->model instanceof TestPrunableModel
                && $event->recordCount === 1
                && $event->modelClass() === TestPrunableModel::class;
        });
    });

    it('dispatches ArchiveCompleted event after successful archive', function () {
        Event::fake([ArchiveCompleted::class]);

        TestPrunableModel::create([
            'name' => 'Old Record',
            'created_at' => now()->subMonths(2),
        ]);

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([TestPrunableModel::class]);
        $listener->handle($event);

        Event::assertDispatched(ArchiveCompleted::class, function ($event) {
            return $event->model instanceof TestPrunableModel
                && $event->result->recordCount === 1
                && $event->modelClass() === TestPrunableModel::class;
        });
    });

    it('dispatches ArchiveFailed event when archive fails', function () {
        Event::fake([ArchiveFailed::class]);
        config(['prunekeeper.fail_silently' => true]);

        $mockExporter = Mockery::mock(Exporter::class);
        $mockExporter->shouldReceive('export')->andThrow(new RuntimeException('Export failed'));
        $mockExporter->shouldReceive('extension')->andReturn('csv');
        app()->instance(CsvExporter::class, $mockExporter);

        TestPrunableModel::create([
            'name' => 'Old Record',
            'created_at' => now()->subMonths(2),
        ]);

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([TestPrunableModel::class]);
        $listener->handle($event);

        Event::assertDispatched(ArchiveFailed::class, function ($event) {
            return $event->model instanceof TestPrunableModel
                && $event->exception->getMessage() === 'Export failed'
                && $event->modelClass() === TestPrunableModel::class;
        });
    });

    it('dispatches ArchiveSkipped event when no records to archive', function () {
        Event::fake([ArchiveSkipped::class]);

        TestPrunableModel::create([
            'name' => 'New Record',
            'created_at' => now(),
        ]);

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([TestPrunableModel::class]);
        $listener->handle($event);

        Event::assertDispatched(ArchiveSkipped::class, function ($event) {
            return $event->model instanceof TestPrunableModel
                && $event->reason === ArchiveSkipped::REASON_NO_RECORDS
                && $event->modelClass() === TestPrunableModel::class;
        });
    });

    it('dispatches ArchiveSkipped event when archiving is disabled', function () {
        Event::fake([ArchiveSkipped::class]);

        $modelClass = new class extends Model
        {
            use ArchivePrunedRecords;
            use Prunable;

            protected $table = 'test_prunable_models';

            protected $guarded = [];

            public function shouldArchiveBeforePruning(): bool
            {
                return false;
            }

            public function prunable()
            {
                return self::where('created_at', '<=', now()->subMonth());
            }
        };

        TestPrunableModel::create([
            'name' => 'Old Record',
            'created_at' => now()->subMonths(2),
        ]);

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([get_class($modelClass)]);
        $listener->handle($event);

        Event::assertDispatched(ArchiveSkipped::class, function ($event) {
            return $event->reason === ArchiveSkipped::REASON_DISABLED;
        });
    });

    it('dispatches ArchiveSkipped event when model has no prunable method', function () {
        Event::fake([ArchiveSkipped::class]);

        $modelClass = new class extends Model
        {
            use ArchivePrunedRecords;

            protected $table = 'test_prunable_models';
        };

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([get_class($modelClass)]);
        $listener->handle($event);

        Event::assertDispatched(ArchiveSkipped::class, function ($event) {
            return $event->reason === ArchiveSkipped::REASON_NO_PRUNABLE_METHOD;
        });
    });
});

describe('Command Events', function () {
    it('dispatches ArchiveStarting event from command', function () {
        Event::fake([ArchiveStarting::class]);

        TestPrunableModel::create([
            'name' => 'Old Record',
            'created_at' => now()->subMonths(2),
        ]);

        $this->artisan('prunekeeper:archive', ['--model' => [TestPrunableModel::class]])
            ->assertExitCode(0);

        Event::assertDispatched(ArchiveStarting::class, function ($event) {
            return $event->model instanceof TestPrunableModel
                && $event->recordCount === 1;
        });
    });

    it('dispatches ArchiveCompleted event from command', function () {
        Event::fake([ArchiveCompleted::class]);

        TestPrunableModel::create([
            'name' => 'Old Record',
            'created_at' => now()->subMonths(2),
        ]);

        $this->artisan('prunekeeper:archive', ['--model' => [TestPrunableModel::class]])
            ->assertExitCode(0);

        Event::assertDispatched(ArchiveCompleted::class, function ($event) {
            return $event->model instanceof TestPrunableModel
                && $event->result->recordCount === 1;
        });
    });

    it('dispatches ArchiveSkipped event from command in pretend mode', function () {
        Event::fake([ArchiveSkipped::class]);

        TestPrunableModel::create([
            'name' => 'Old Record',
            'created_at' => now()->subMonths(2),
        ]);

        $this->artisan('prunekeeper:archive', [
            '--model' => [TestPrunableModel::class],
            '--pretend' => true,
        ])
            ->assertExitCode(0);

        Event::assertDispatched(ArchiveSkipped::class, function ($event) {
            return $event->model instanceof TestPrunableModel
                && $event->reason === ArchiveSkipped::REASON_PRETEND_MODE;
        });
    });

    it('dispatches ArchiveSkipped event from command when no records', function () {
        Event::fake([ArchiveSkipped::class]);

        TestPrunableModel::create([
            'name' => 'New Record',
            'created_at' => now(),
        ]);

        $this->artisan('prunekeeper:archive', ['--model' => [TestPrunableModel::class]])
            ->assertExitCode(0);

        Event::assertDispatched(ArchiveSkipped::class, function ($event) {
            return $event->model instanceof TestPrunableModel
                && $event->reason === ArchiveSkipped::REASON_NO_RECORDS;
        });
    });
});

describe('Backward Compatibility', function () {
    it('still fires legacy callbacks alongside events', function () {
        $beforeCalled = false;
        $afterCalled = false;

        Prunekeeper::beforeArchiving(function ($model) use (&$beforeCalled) {
            $beforeCalled = true;
        });
        Prunekeeper::afterArchiving(function ($model, $result) use (&$afterCalled) {
            $afterCalled = true;
        });

        TestPrunableModel::create([
            'name' => 'Old Record',
            'created_at' => now()->subMonths(2),
        ]);

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([TestPrunableModel::class]);
        $listener->handle($event);

        expect($beforeCalled)->toBeTrue();
        expect($afterCalled)->toBeTrue();
    });

    it('fires both callbacks and events together', function () {
        Event::fake([ArchiveStarting::class, ArchiveCompleted::class]);

        $callbackFired = false;

        Prunekeeper::beforeArchiving(function ($model) use (&$callbackFired) {
            $callbackFired = true;
        });

        TestPrunableModel::create([
            'name' => 'Old Record',
            'created_at' => now()->subMonths(2),
        ]);

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([TestPrunableModel::class]);
        $listener->handle($event);

        expect($callbackFired)->toBeTrue();
        Event::assertDispatched(ArchiveStarting::class);
        Event::assertDispatched(ArchiveCompleted::class);
    });
});
