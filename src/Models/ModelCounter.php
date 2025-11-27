<?php

namespace Rejoose\ModelCounter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ModelCounter extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'count' => 'integer',
        ];
    }

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('counter.table_name', 'model_counters');
    }

    /**
     * Get the current counter value from the database for a given owner and key.
     */
    public static function valueFor(\Illuminate\Database\Eloquent\Model $owner, string $key): int
    {
        return static::where([
            'owner_type' => $owner::class,
            'owner_id' => $owner->getKey(),
            'key' => $key,
        ])->value('count') ?? 0;
    }

    /**
     * Add a delta to an existing counter (or create it if it doesn't exist).
     *
     * Uses upsert for atomic operations with proper MySQL/PostgreSQL compatibility.
     */
    public static function addDelta(\Illuminate\Database\Eloquent\Model $owner, string $key, int $amount): void
    {
        $data = [
            'owner_type' => $owner::class,
            'owner_id' => $owner->getKey(),
            'key' => $key,
            'count' => $amount,
            'updated_at' => now(),
        ];

        // Try to update existing record
        $updated = static::where([
            'owner_type' => $owner::class,
            'owner_id' => $owner->getKey(),
            'key' => $key,
        ])->update([
            'count' => DB::raw("count + {$amount}"),
            'updated_at' => now(),
        ]);

        // If no record was updated, create a new one
        if (!$updated) {
            $data['created_at'] = now();
            static::insertOrIgnore($data);

            // If insert failed due to race condition, try update again
            if (!$updated) {
                static::where([
                    'owner_type' => $owner::class,
                    'owner_id' => $owner->getKey(),
                    'key' => $key,
                ])->update([
                    'count' => DB::raw("count + {$amount}"),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reset a counter to zero.
     */
    public static function resetValue(\Illuminate\Database\Eloquent\Model $owner, string $key): void
    {
        static::updateOrCreate(
            [
                'owner_type' => $owner::class,
                'owner_id' => $owner->getKey(),
                'key' => $key,
            ],
            [
                'count' => 0,
            ]
        );
    }

    /**
     * Set a counter to a specific value.
     */
    public static function setValue(\Illuminate\Database\Eloquent\Model $owner, string $key, int $value): void
    {
        static::updateOrCreate(
            [
                'owner_type' => $owner::class,
                'owner_id' => $owner->getKey(),
                'key' => $key,
            ],
            [
                'count' => $value,
            ]
        );
    }

    /**
     * Get all counters for a given owner.
     */
    public static function allForOwner(\Illuminate\Database\Eloquent\Model $owner): array
    {
        return static::where([
            'owner_type' => $owner::class,
            'owner_id' => $owner->getKey(),
        ])
            ->pluck('count', 'key')
            ->toArray();
    }

    /**
     * Define the polymorphic relationship to the owner.
     */
    public function owner()
    {
        return $this->morphTo();
    }
}

