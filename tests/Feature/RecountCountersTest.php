<?php

namespace Rejoose\ModelCounter\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Rejoose\ModelCounter\Contracts\DefinesCounters;
use Rejoose\ModelCounter\Counter;
use Rejoose\ModelCounter\CounterDefinition;
use Rejoose\ModelCounter\Models\ModelCounter;
use Rejoose\ModelCounter\Tests\TestCase;
use Rejoose\ModelCounter\Traits\HasCounters;

class RecountCountersTest extends TestCase
{
    protected function tearDown(): void
    {
        RecountSubject::query()->delete();
        ModelCounter::query()->delete();

        parent::tearDown();
    }

    public function test_recount_corrects_stored_counters_from_source(): void
    {
        $s1 = RecountSubject::create(['name' => 'one', 'things_count' => 3]);
        $s2 = RecountSubject::create(['name' => 'two', 'things_count' => 5]);

        // Deliberately wrong stored values.
        Counter::set($s1, 'things', 999);
        Counter::set($s2, 'things', 0);

        $exit = Artisan::call('counter:recount', ['model' => RecountSubject::class]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(3, $s1->fresh()->counter('things'));
        $this->assertSame(5, $s2->fresh()->counter('things'));
    }

    public function test_id_option_targets_only_the_given_records(): void
    {
        $s1 = RecountSubject::create(['name' => 'one', 'things_count' => 3]);
        $s2 = RecountSubject::create(['name' => 'two', 'things_count' => 5]);

        Counter::set($s1, 'things', 999);
        Counter::set($s2, 'things', 222);

        $exit = Artisan::call('counter:recount', ['model' => RecountSubject::class, '--id' => [$s1->getKey()]]);

        $this->assertSame(0, $exit);
        $this->assertSame(3, $s1->fresh()->counter('things'));
        // s2 untouched.
        $this->assertSame(222, $s2->fresh()->counter('things'));
    }

    public function test_recount_spans_multiple_chunks(): void
    {
        $subjects = [];
        foreach (range(1, 5) as $i) {
            $subjects[] = RecountSubject::create(['name' => "s{$i}", 'things_count' => $i]);
            Counter::set($subjects[$i - 1], 'things', 0);
        }

        $exit = Artisan::call('counter:recount', ['model' => RecountSubject::class, '--chunk' => 1]);

        $this->assertSame(0, $exit);
        foreach ($subjects as $idx => $subject) {
            $this->assertSame($idx + 1, $subject->fresh()->counter('things'));
        }
    }

    public function test_unresolvable_model_fails_gracefully(): void
    {
        $exit = Artisan::call('counter:recount', ['model' => 'App\\Models\\DoesNotExist']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Could not resolve a model class', Artisan::output());
    }

    public function test_model_without_defines_counters_fails(): void
    {
        $exit = Artisan::call('counter:recount', ['model' => RecountPlainModel::class]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('must implement DefinesCounters', Artisan::output());
    }

    public function test_invalid_from_date_fails_gracefully(): void
    {
        $exit = Artisan::call('counter:recount', ['model' => RecountSubject::class, '--from' => 'not-a-date']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid --from/--to date', Artisan::output());
    }

    public function test_from_later_than_to_fails(): void
    {
        $exit = Artisan::call('counter:recount', [
            'model' => RecountSubject::class,
            '--from' => '2026-06-08',
            '--to' => '2026-06-01',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--from must not be later than --to', Artisan::output());
    }
}

class RecountSubject extends Model implements DefinesCounters
{
    use HasCounters;

    protected $table = 'recount_subjects';

    protected $guarded = [];

    public function counterDefinitions(): array
    {
        return [
            'things' => CounterDefinition::make('things')
                ->recountUsing(fn () => (int) $this->things_count),
        ];
    }
}

class RecountPlainModel extends Model
{
    protected $table = 'recount_plain_models';

    protected $guarded = [];
}
