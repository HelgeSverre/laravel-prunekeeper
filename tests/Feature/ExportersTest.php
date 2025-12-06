<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\Exceptions\InvalidColumnException;
use HelgeSverre\Prunekeeper\Exporters\CsvExporter;
use HelgeSverre\Prunekeeper\Exporters\SqlExporter;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestPrunableModel;

beforeEach(function () {
    // Create test records
    TestPrunableModel::create([
        'name' => 'Test User 1',
        'email' => 'user1@example.com',
        'metadata' => ['role' => 'admin'],
        'created_at' => now()->subMonths(2),
    ]);

    TestPrunableModel::create([
        'name' => 'Test User 2',
        'email' => 'user2@example.com',
        'metadata' => null,
        'created_at' => now()->subMonths(3),
    ]);
});

describe('CsvExporter', function () {
    it('exports all columns by default', function () {
        $exporter = new CsvExporter;
        $query = TestPrunableModel::query();

        $tempFile = $exporter->export($query);
        $content = file_get_contents($tempFile);

        expect($content)
            ->toContain('id')
            ->toContain('name')
            ->toContain('email')
            ->toContain('metadata')
            ->toContain('created_at')
            ->toContain('Test User 1')
            ->toContain('Test User 2');

        @unlink($tempFile);
    });

    it('exports specific columns when provided', function () {
        $exporter = new CsvExporter;
        $query = TestPrunableModel::query();

        $tempFile = $exporter->export($query, ['name', 'email']);
        $content = file_get_contents($tempFile);

        expect($content)
            ->toContain('name')
            ->toContain('email')
            ->toContain('Test User 1')
            ->not->toContain('metadata');

        @unlink($tempFile);
    });

    it('handles null values', function () {
        $exporter = new CsvExporter;
        $query = TestPrunableModel::where('name', 'Test User 2');

        $tempFile = $exporter->export($query);
        $content = file_get_contents($tempFile);

        // Null metadata should be handled gracefully
        expect($content)->toContain('Test User 2');

        @unlink($tempFile);
    });

    it('returns csv as extension', function () {
        $exporter = new CsvExporter;
        expect($exporter->extension())->toBe('csv');
    });
});

describe('InvalidColumnException', function () {
    it('throws when specifying non-existent columns', function () {
        $exporter = new CsvExporter;
        $query = TestPrunableModel::query();

        $exporter->export($query, ['name', 'non_existent_column']);
    })->throws(InvalidColumnException::class);

    it('provides helpful error message with available columns', function () {
        $exporter = new CsvExporter;
        $query = TestPrunableModel::query();

        try {
            $exporter->export($query, ['name', 'invalid_col']);
        } catch (InvalidColumnException $e) {
            expect($e->getMessage())
                ->toContain('invalid_col')
                ->toContain('name')
                ->toContain('email')
                ->toContain('test_prunable_models');

            return;
        }

        $this->fail('Expected InvalidColumnException was not thrown');
    });
});

describe('SqlExporter', function () {
    it('generates valid INSERT statements', function () {
        $exporter = new SqlExporter;
        $query = TestPrunableModel::query();

        $tempFile = $exporter->export($query);
        $content = file_get_contents($tempFile);

        // Table name should be wrapped (quotes vary by database: backticks for MySQL, double quotes for PostgreSQL/SQLite)
        expect($content)
            ->toMatch('/INSERT INTO ["`]test_prunable_models["`]/')
            ->toContain('Test User 1')
            ->toContain('Test User 2');

        @unlink($tempFile);
    });

    it('exports specific columns when provided', function () {
        $exporter = new SqlExporter;
        $query = TestPrunableModel::query();

        $tempFile = $exporter->export($query, ['name', 'email']);
        $content = file_get_contents($tempFile);

        // Column names should be wrapped (quotes vary by database)
        expect($content)
            ->toMatch('/["`]name["`], ["`]email["`]/')
            ->not->toMatch('/["`]metadata["`]/');

        @unlink($tempFile);
    });

    it('escapes string values correctly', function () {
        TestPrunableModel::create([
            'name' => "Test's \"Special\" User",
            'email' => 'special@example.com',
            'created_at' => now()->subMonths(2),
        ]);

        $exporter = new SqlExporter;
        $query = TestPrunableModel::where('email', 'special@example.com');

        $tempFile = $exporter->export($query);
        $content = file_get_contents($tempFile);

        // Should contain properly escaped string
        expect($content)->toContain('INSERT INTO');

        @unlink($tempFile);
    });

    it('handles NULL values', function () {
        $exporter = new SqlExporter;
        $query = TestPrunableModel::where('name', 'Test User 2');

        $tempFile = $exporter->export($query);
        $content = file_get_contents($tempFile);

        expect($content)->toContain('NULL');

        @unlink($tempFile);
    });

    it('returns sql as extension', function () {
        $exporter = new SqlExporter;
        expect($exporter->extension())->toBe('sql');
    });

    it('includes header comments', function () {
        $exporter = new SqlExporter;
        $query = TestPrunableModel::query();

        $tempFile = $exporter->export($query);
        $content = file_get_contents($tempFile);

        expect($content)
            ->toContain('-- Created with Laravel Prunekeeper')
            ->toContain('-- Table: test_prunable_models')
            ->toContain('-- Generated:');

        @unlink($tempFile);
    });
});
