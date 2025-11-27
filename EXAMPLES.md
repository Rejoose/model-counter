# Usage Examples

## Basic Usage Examples

### 1. Track Product Downloads

```php
use App\Models\Product;
use Rejoose\ModelCounter\Traits\HasCounters;

class Product extends Model
{
    use HasCounters;
}

// In your controller
class ProductController extends Controller
{
    public function download(Product $product)
    {
        // Generate download link
        $url = $product->generateDownloadUrl();
        
        // Increment download counter (super fast!)
        $product->incrementCounter('downloads');
        
        return redirect($url);
    }
    
    public function show(Product $product)
    {
        return view('products.show', [
            'product' => $product,
            'downloads' => $product->counter('downloads'),
        ]);
    }
}
```

### 2. Track User Activity

```php
class User extends Authenticatable
{
    use HasCounters;
}

// Track various user activities
class UserActivityTracker
{
    public function trackLogin(User $user): void
    {
        $user->incrementCounter('logins');
        $user->incrementCounter('login_streak');
    }
    
    public function trackPost(User $user): void
    {
        $user->incrementCounter('posts_created');
    }
    
    public function trackComment(User $user): void
    {
        $user->incrementCounter('comments_made');
    }
    
    public function getStats(User $user): array
    {
        return $user->counters([
            'logins',
            'posts_created',
            'comments_made',
            'profile_views',
        ]);
    }
}
```

### 3. API Rate Limiting

```php
class Organization extends Model
{
    use HasCounters;
    
    public function canMakeApiCall(): bool
    {
        return $this->counter('api_calls_today') < $this->api_rate_limit;
    }
    
    public function recordApiCall(): void
    {
        $this->incrementCounter('api_calls_today');
        $this->incrementCounter('api_calls_total');
    }
}

// In your API middleware
class ApiRateLimiter
{
    public function handle($request, Closure $next)
    {
        $org = $request->user()->organization;
        
        if (!$org->canMakeApiCall()) {
            return response()->json([
                'error' => 'Rate limit exceeded',
                'limit' => $org->api_rate_limit,
                'used' => $org->counter('api_calls_today'),
            ], 429);
        }
        
        $org->recordApiCall();
        
        return $next($request);
    }
}

// Reset daily counters (in a scheduled command)
class ResetDailyCounters extends Command
{
    public function handle()
    {
        Organization::chunk(100, function ($organizations) {
            foreach ($organizations as $org) {
                $org->resetCounter('api_calls_today');
            }
        });
    }
}
```

### 4. Content Popularity Tracking

```php
class Article extends Model
{
    use HasCounters;
}

class ArticleController extends Controller
{
    public function show(Article $article)
    {
        // Track view
        $article->incrementCounter('views');
        
        // Track unique views (using session)
        if (!session()->has("viewed_article_{$article->id}")) {
            $article->incrementCounter('unique_views');
            session()->put("viewed_article_{$article->id}", true);
        }
        
        // Get popular articles
        $stats = [
            'views' => $article->counter('views'),
            'unique_views' => $article->counter('unique_views'),
            'shares' => $article->counter('shares'),
            'likes' => $article->counter('likes'),
        ];
        
        return view('articles.show', compact('article', 'stats'));
    }
    
    public function like(Article $article)
    {
        $article->incrementCounter('likes');
        auth()->user()->incrementCounter('articles_liked');
        
        return back();
    }
    
    public function share(Article $article)
    {
        $article->incrementCounter('shares');
        
        return back();
    }
}
```

### 5. E-commerce Analytics

```php
class Store extends Model
{
    use HasCounters;
}

class OrderService
{
    public function createOrder(Store $store, array $items): Order
    {
        $order = Order::create([
            'store_id' => $store->id,
            'items' => $items,
        ]);
        
        // Track metrics
        $store->incrementCounter('orders_today');
        $store->incrementCounter('orders_total');
        $store->incrementCounter('revenue_today', $order->total);
        $store->incrementCounter('revenue_total', $order->total);
        $store->incrementCounter('items_sold', count($items));
        
        return $order;
    }
    
    public function getDashboardStats(Store $store): array
    {
        return [
            'today' => [
                'orders' => $store->counter('orders_today'),
                'revenue' => $store->counter('revenue_today'),
            ],
            'total' => [
                'orders' => $store->counter('orders_total'),
                'revenue' => $store->counter('revenue_total'),
                'items_sold' => $store->counter('items_sold'),
            ],
        ];
    }
}
```

### 6. Video Streaming Analytics

```php
class Video extends Model
{
    use HasCounters;
}

class VideoController extends Controller
{
    public function play(Video $video)
    {
        // Track play
        $video->incrementCounter('plays');
        
        return view('videos.player', compact('video'));
    }
    
    public function trackProgress(Request $request, Video $video)
    {
        $watchTime = $request->input('watch_time'); // in seconds
        
        // Track watch time
        $video->incrementCounter('total_watch_time', $watchTime);
        
        // Track completion
        if ($request->input('completed')) {
            $video->incrementCounter('completions');
        }
        
        return response()->json(['success' => true]);
    }
    
    public function stats(Video $video)
    {
        $plays = $video->counter('plays');
        $completions = $video->counter('completions');
        $totalWatchTime = $video->counter('total_watch_time');
        
        return [
            'plays' => $plays,
            'completions' => $completions,
            'completion_rate' => $plays > 0 ? ($completions / $plays) * 100 : 0,
            'avg_watch_time' => $plays > 0 ? $totalWatchTime / $plays : 0,
        ];
    }
}
```

### 7. Gaming Leaderboard

```php
class Player extends Model
{
    use HasCounters;
}

class GameService
{
    public function recordWin(Player $player, int $points): void
    {
        $player->incrementCounter('wins');
        $player->incrementCounter('total_points', $points);
        $player->incrementCounter('games_played');
    }
    
    public function recordLoss(Player $player): void
    {
        $player->incrementCounter('losses');
        $player->incrementCounter('games_played');
    }
    
    public function getLeaderboard(int $limit = 10): Collection
    {
        return Player::query()
            ->with('counters')
            ->get()
            ->map(function (Player $player) {
                return [
                    'player' => $player,
                    'wins' => $player->counter('wins'),
                    'losses' => $player->counter('losses'),
                    'points' => $player->counter('total_points'),
                    'games' => $player->counter('games_played'),
                ];
            })
            ->sortByDesc('points')
            ->take($limit);
    }
}
```

### 8. File Storage Tracking

```php
class Workspace extends Model
{
    use HasCounters;
    
    public int $storage_limit = 10 * 1024 * 1024 * 1024; // 10GB
}

class FileUploadService
{
    public function upload(Workspace $workspace, UploadedFile $file): void
    {
        $fileSize = $file->getSize();
        
        // Check quota
        if ($workspace->counter('storage_used') + $fileSize > $workspace->storage_limit) {
            throw new StorageQuotaExceededException();
        }
        
        // Upload file
        $file->store("workspaces/{$workspace->id}");
        
        // Track usage
        $workspace->incrementCounter('storage_used', $fileSize);
        $workspace->incrementCounter('files_uploaded');
    }
    
    public function delete(Workspace $workspace, File $file): void
    {
        Storage::delete($file->path);
        
        // Decrease usage
        $workspace->decrementCounter('storage_used', $file->size);
        $workspace->decrementCounter('files_uploaded');
    }
    
    public function getStorageStats(Workspace $workspace): array
    {
        $used = $workspace->counter('storage_used');
        $limit = $workspace->storage_limit;
        
        return [
            'used' => $used,
            'limit' => $limit,
            'available' => $limit - $used,
            'percentage' => ($used / $limit) * 100,
            'files_count' => $workspace->counter('files_uploaded'),
        ];
    }
}
```

## Advanced Patterns

### Manual Sync with Specific Patterns

```bash
# Sync only user counters
php artisan counter:sync --pattern="user:*"

# Sync only specific organization
php artisan counter:sync --pattern="organization:123:*"

# Dry run to see what would be synced
php artisan counter:sync --dry-run
```

### Using Counter Directly Without Trait

```php
use Rejoose\ModelCounter\Counter;

$model = AnyModel::find(1);

Counter::increment($model, 'metric_name', 5);
$value = Counter::get($model, 'metric_name');
Counter::reset($model, 'metric_name');
```

### Batch Counter Operations

```php
// Track multiple related metrics at once
$order = Order::create($data);

$store->incrementCounter('orders');
$store->incrementCounter('revenue', $order->total);

$order->items->each(function ($item) use ($store) {
    $store->incrementCounter('item_sales');
    $item->product->incrementCounter('sales');
});
```

