<?php

use Illuminate\Database\Eloquent\Model;
use Rejoose\ModelCounter\Traits\HasCounters;

// Define test models
class RelationTestUser extends Model
{
    use HasCounters;

    protected $table = 'relation_test_users';

    protected $guarded = [];

    public function posts()
    {
        return $this->hasMany(RelationTestPost::class, 'user_id');
    }
}

class RelationTestPost extends Model
{
    protected $table = 'relation_test_posts';

    protected $guarded = [];
}

beforeEach(function () {
    // Create test user table
    if (! $this->app['db']->connection()->getSchemaBuilder()->hasTable('relation_test_users')) {
        $this->app['db']->connection()->getSchemaBuilder()->create('relation_test_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    // Create test posts table
    if (! $this->app['db']->connection()->getSchemaBuilder()->hasTable('relation_test_posts')) {
        $this->app['db']->connection()->getSchemaBuilder()->create('relation_test_posts', function ($table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('title');
            $table->timestamps();
        });
    }

    $this->user = RelationTestUser::create(['name' => 'Test User']);
});

afterEach(function () {
    RelationTestUser::query()->delete();
    RelationTestPost::query()->delete();
});

test('can recount relation', function () {
    // Create 3 posts
    RelationTestPost::create(['user_id' => $this->user->id, 'title' => 'Post 1']);
    RelationTestPost::create(['user_id' => $this->user->id, 'title' => 'Post 2']);
    RelationTestPost::create(['user_id' => $this->user->id, 'title' => 'Post 3']);

    // Recount
    $this->user->posts()->recount();

    // Check counter - default key is table name 'relation_test_posts'
    expect($this->user->counter('relation_test_posts'))->toBe(3);
});

test('can recount relation with custom key', function () {
    RelationTestPost::create(['user_id' => $this->user->id, 'title' => 'Post 1']);

    $this->user->posts()->recount('custom_posts_count');

    expect($this->user->counter('custom_posts_count'))->toBe(1);
});

test('scope with counter', function () {
    $this->user->setCounter('test_posts', 5);

    $user = RelationTestUser::withCounter('test_posts')->first();

    expect($user->test_posts_count)->toBe(5);
});

test('scope order by counter', function () {
    $user1 = RelationTestUser::create(['name' => 'User 1']);
    $user1->setCounter('points', 10);

    $user2 = RelationTestUser::create(['name' => 'User 2']);
    $user2->setCounter('points', 50);

    $user3 = RelationTestUser::create(['name' => 'User 3']);
    $user3->setCounter('points', 5);

    $users = RelationTestUser::orderByCounter('points', 'desc')->get();

    expect($users[0]->id)->toBe($user2->id)
        ->and($users[1]->id)->toBe($user1->id)
        ->and($users[2]->id)->toBe($user3->id);
});
