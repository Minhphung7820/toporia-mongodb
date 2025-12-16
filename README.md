# Toporia MongoDB

MongoDB ODM (Object Document Mapper) for Toporia Framework with embedded documents, references, and aggregation pipelines.

## Features

- **Document-Oriented Models** - ODM similar to ORM for MongoDB collections
- **Embedded Documents** - Support for nested documents (EmbedsOne, EmbedsMany)
- **References** - Document references (ReferencesOne, ReferencesMany)
- **Aggregation Pipelines** - Fluent interface for MongoDB aggregation
- **Query Builder** - MongoDB-specific query builder with all operators
- **Schema Management** - Collection and index management

## Requirements

- **PHP**: >= 8.1
- **Toporia Framework**: ^1.0
- **mongodb/mongodb**: ^1.17
- **ext-mongodb**: MongoDB PHP extension

## Installation

```bash
composer require toporia/mongodb
```

## Auto-Discovery

This package uses Toporia's **Package Auto-Discovery** system. After installation:

- **Service Provider** is automatically registered - no manual registration required
- **Configuration** is automatically discovered from `extra.toporia.config` in composer.json

To rebuild the package manifest manually:

```bash
php console package:discover
```

## Setup

### 1. Install MongoDB PHP Extension

```bash
# Ubuntu/Debian
sudo pecl install mongodb
echo "extension=mongodb.so" | sudo tee /etc/php/8.1/cli/conf.d/mongodb.ini

# macOS with Homebrew
pecl install mongodb
```

### 2. Publish Config (optional)

```bash
php console vendor:publish --provider="Toporia\MongoDB\MongoDBServiceProvider"
# Or with tag
php console vendor:publish --tag=mongodb-config
```

### 3. Configure Environment

Add to your `.env` file:

```env
MONGO_HOST=localhost
MONGO_PORT=27017
MONGO_DATABASE=your_database
MONGO_USERNAME=
MONGO_PASSWORD=
MONGO_AUTH_DATABASE=admin
```

## Configuration

```php
// config/mongodb.php
return [
    'default' => 'mongodb',

    'connections' => [
        'mongodb' => [
            'driver' => 'mongodb',
            'host' => env('MONGO_HOST', 'localhost'),
            'port' => env('MONGO_PORT', 27017),
            'database' => env('MONGO_DATABASE', 'toporia'),
            'username' => env('MONGO_USERNAME', ''),
            'password' => env('MONGO_PASSWORD', ''),
            'options' => [
                'authSource' => env('MONGO_AUTH_DATABASE', 'admin'),
            ],
        ],
    ],
];
```

## Usage

### Basic Model

```php
use Toporia\MongoDB\ORM\MongoDBModel;

class Post extends MongoDBModel
{
    protected static string $collection = 'posts';

    protected static array $fillable = ['title', 'content', 'author_id'];

    protected static array $casts = [
        'created_at' => 'datetime',
        'views' => 'integer',
    ];
}

// Create
$post = Post::create([
    'title' => 'Hello MongoDB',
    'content' => 'First post',
]);

// Find
$post = Post::find($id);

// Query
$posts = Post::where('author_id', $userId)->get();
```

### Embedded Documents

```php
class User extends MongoDBModel
{
    protected static string $collection = 'users';

    public function address()
    {
        return $this->embedsOne(Address::class);
    }

    public function phones()
    {
        return $this->embedsMany(Phone::class);
    }
}

// Access embedded
$user = User::find($id);
echo $user->address->city;

foreach ($user->phones as $phone) {
    echo $phone->number;
}
```

### References

```php
class Author extends MongoDBModel
{
    public function posts()
    {
        return $this->referencesMany(Post::class, 'author_id');
    }
}

class Post extends MongoDBModel
{
    public function author()
    {
        return $this->belongsToReference(Author::class, 'author_id');
    }
}

// Access references
$author = Author::find($id);
$posts = $author->posts; // Lazy loaded
```

### Aggregation Pipeline

```php
$results = Post::aggregate()
    ->match(['status' => 'published'])
    ->group([
        '_id' => '$author_id',
        'total_posts' => ['$sum' => 1],
        'total_views' => ['$sum' => '$views'],
    ])
    ->sort(['total_posts' => -1])
    ->limit(10)
    ->get();
```

### Query Builder

```php
// MongoDB-specific operators
$posts = Post::where('views', '>', 100)
    ->whereIn('tags', ['php', 'mongodb'])
    ->whereExists('featured_image')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();

// Text search
$results = Post::whereText('mongodb tutorial')->get();

// Geo queries
$nearby = Location::whereNear('coordinates', [
    'latitude' => 40.7128,
    'longitude' => -74.0060,
], 5000)->get(); // Within 5km
```

## Helper Functions

```php
mongodb();                          // Get MongoDB manager
mongodb_connection('mongodb');      // Get specific connection
mongodb_collection('posts');        // Get collection directly
```

## License

MIT
