<?php

namespace Rejoose\ModelCounter\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Rejoose\ModelCounter\Contracts\DefinesCounters;
use Rejoose\ModelCounter\Counter;
use Rejoose\ModelCounter\CounterDefinition;
use Rejoose\ModelCounter\Enums\CounterVerifyMode;
use Rejoose\ModelCounter\Enums\Interval;
use Rejoose\ModelCounter\Models\ModelCounter;
use Rejoose\ModelCounter\Tests\TestCase;
use Rejoose\ModelCounter\Traits\HasCounters;

enum DefinedCounterKey: string
{
    case Logins = 'logins';
    case Total = 'total_things';
    case Updates = 'updates_cumulative';
}

class DefinedCounterUser extends Model implements DefinesCounters
{
    use HasCounters;

    protected $table = 'defined_counter_users';

    protected $guarded = [];

    /** @var Carbon[] */
    public array $loginDates = [];

    public int $totalThings = 0;

    /** @var Carbon[] Snapshot of latest update timestamps (one per record). */
    public array $latestUpdateAt = [];

    public function counterDefinitions(): array
    {
        return [
            DefinedCounterKey::Logins->value => CounterDefinition::make(DefinedCounterKey::Logins)
                ->interval(Interval::Day)
                ->recountUsing(fn (Carbon $start, Carbon $end) => collect($this->loginDates)
                    ->filter(fn (Carbon $d) => $d->betweenIncluded($start, $end))
                    ->count()),

            DefinedCounterKey::Total->value => CounterDefinition::make(DefinedCounterKey::Total)
                ->recountUsing(fn () => $this->totalThings),

            DefinedCounterKey::Updates->value => CounterDefinition::make(DefinedCounterKey::Updates)
                ->interval(Interval::Day)
                ->verifyMode(CounterVerifyMode::AtLeast)
                ->recountUsing(fn (Carbon $start, Carbon $end) => collect($this->latestUpdateAt)
                    ->filter(fn (Carbon $d) => $d->betweenIncluded($start, $end))
                    ->count()),
        ];
    }
}

class DefinedCountersTest extends TestCase
{
    protected DefinedCounterUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = DefinedCounterUser::create(['name' => 'Defined User']);
    }

    protected function tearDown(): void
    {
        DefinedCounterUser::query()->delete();
        ModelCounter::query()->delete();
        parent::tearDown();
    }

    public function test_counter_definition_normalizes_enum_keys(): void
    {
        $def = CounterDefinition::make(DefinedCounterKey::Logins);

        $this->assertSame('logins', $def->key);
    }

    public function test_trait_methods_accept_backed_enums(): void
    {
        $this->user->incrementCounter(DefinedCounterKey::Total, 5);

        $this->assertSame(5, $this->user->counter(DefinedCounterKey::Total));
        $this->assertSame(5, $this->user->counter('total_things'));
    }

    public function test_recount_all_counters_writes_per_period_for_interval_keys(): void
    {
        $today = now()->startOfDay();
        $yesterday = now()->subDay()->startOfDay();
        $twoDaysAgo = now()->subDays(2)->startOfDay();

        $this->user->loginDates = [
            $today, $today, $today,    // 3 today
            $yesterday,                 // 1 yesterday
            $twoDaysAgo, $twoDaysAgo,  // 2 two days ago
        ];

        $results = $this->user->recountAllCounters($twoDaysAgo, $today);

        $this->assertSame([
            $today->format('Y-m-d') => 3,
            $yesterday->format('Y-m-d') => 1,
            $twoDaysAgo->format('Y-m-d') => 2,
        ], $results['logins']);

        $this->assertSame(3, $this->user->counter(DefinedCounterKey::Logins, Interval::Day, $today));
        $this->assertSame(1, $this->user->counter(DefinedCounterKey::Logins, Interval::Day, $yesterday));
        $this->assertSame(2, $this->user->counter(DefinedCounterKey::Logins, Interval::Day, $twoDaysAgo));
    }

    public function test_recount_all_counters_writes_global_value_for_non_interval_keys(): void
    {
        $this->user->totalThings = 42;

        $results = $this->user->recountAllCounters();

        $this->assertSame(42, $results['total_things']);
        $this->assertSame(42, $this->user->counter(DefinedCounterKey::Total));
    }

    public function test_verify_counter_reports_match_when_stored_equals_source(): void
    {
        $today = now()->startOfDay();
        $this->user->loginDates = [$today, $today];

        Counter::set($this->user, DefinedCounterKey::Logins->value, 2, Interval::Day, $today);

        $report = $this->user->verifyCounter(DefinedCounterKey::Logins, $today, $today);

        $this->assertTrue($report['matches']);
        $this->assertSame(2, $report['stored']);
        $this->assertSame(2, $report['actual']);
        $this->assertTrue($report['periods'][$today->format('Y-m-d')]['matches']);
    }

    public function test_verify_counter_detects_drift_per_period(): void
    {
        $today = now()->startOfDay();
        $yesterday = now()->subDay()->startOfDay();

        $this->user->loginDates = [$today, $today, $yesterday];

        // Stored values intentionally wrong for today
        Counter::set($this->user, DefinedCounterKey::Logins->value, 99, Interval::Day, $today);
        Counter::set($this->user, DefinedCounterKey::Logins->value, 1, Interval::Day, $yesterday);

        $report = $this->user->verifyCounter(DefinedCounterKey::Logins, $yesterday, $today);

        $this->assertFalse($report['matches']);
        $this->assertFalse($report['periods'][$today->format('Y-m-d')]['matches']);
        $this->assertTrue($report['periods'][$yesterday->format('Y-m-d')]['matches']);
        $this->assertSame(99, $report['periods'][$today->format('Y-m-d')]['stored']);
        $this->assertSame(2, $report['periods'][$today->format('Y-m-d')]['actual']);
    }

    public function test_verify_counter_does_not_write(): void
    {
        $today = now()->startOfDay();
        $this->user->loginDates = [$today, $today];

        // Nothing has been written yet — stored should be 0, actual 2.
        $report = $this->user->verifyCounter(DefinedCounterKey::Logins, $today, $today);

        $this->assertFalse($report['matches']);
        $this->assertSame(0, $report['stored']);
        $this->assertSame(2, $report['actual']);

        // Confirm verify did not persist anything.
        $this->assertSame(0, $this->user->counter(DefinedCounterKey::Logins, Interval::Day, $today));
    }

    public function test_verify_counter_throws_for_undefined_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->user->verifyCounter('not_declared');
    }

    public function test_verify_all_counters_returns_report_per_definition(): void
    {
        $this->user->totalThings = 7;

        $report = $this->user->verifyAllCounters();

        $this->assertArrayHasKey('total_things', $report);
        $this->assertArrayHasKey('logins', $report);
        $this->assertSame(7, $report['total_things']['actual']);
    }

    public function test_at_least_mode_passes_when_stored_exceeds_actual(): void
    {
        $today = now()->startOfDay();

        // Record updated 3 times today; only the latest updated_at is visible to the source query.
        $this->user->latestUpdateAt = [$today];
        Counter::set($this->user, DefinedCounterKey::Updates->value, 3, Interval::Day, $today);

        $report = $this->user->verifyCounter(DefinedCounterKey::Updates, $today, $today);

        $this->assertTrue($report['matches']);
        $this->assertSame('at_least', $report['mode']);
        $this->assertSame(3, $report['stored']);
        $this->assertSame(1, $report['actual']);
    }

    public function test_at_least_mode_fails_when_stored_is_below_actual(): void
    {
        $today = now()->startOfDay();

        $this->user->latestUpdateAt = [$today, $today, $today];
        Counter::set($this->user, DefinedCounterKey::Updates->value, 2, Interval::Day, $today);

        $report = $this->user->verifyCounter(DefinedCounterKey::Updates, $today, $today);

        $this->assertFalse($report['matches']);
        $this->assertSame(2, $report['stored']);
        $this->assertSame(3, $report['actual']);
    }

    public function test_recount_all_skips_at_least_counters(): void
    {
        $today = now()->startOfDay();

        // Pre-existing cumulative event count we must NOT clobber.
        Counter::set($this->user, DefinedCounterKey::Updates->value, 7, Interval::Day, $today);
        $this->user->latestUpdateAt = [$today]; // source query would say 1

        $results = $this->user->recountAllCounters($today, $today);

        $this->assertIsArray($results['updates_cumulative']);
        $this->assertTrue($results['updates_cumulative']['skipped']);
        $this->assertSame(7, $this->user->counter(DefinedCounterKey::Updates, Interval::Day, $today));
    }

    public function test_recount_all_throws_when_model_does_not_implement_contract(): void
    {
        $bareModel = new class extends Model
        {
            use HasCounters;

            protected $table = 'defined_counter_users';

            protected $guarded = [];
        };

        $this->expectException(\LogicException::class);
        $bareModel->recountAllCounters();
    }
}
