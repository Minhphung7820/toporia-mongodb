<?php

declare(strict_types=1);

namespace Toporia\MongoDB;

use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\MongoDB\Connection\MongoDBConnection;
use Toporia\MongoDB\Contracts\MongoDBConnectionInterface;
use Toporia\MongoDB\ORM\MongoDBModel;

/**
 * MongoDBServiceProvider
 *
 * Service provider for registering MongoDB services with the Toporia Framework.
 *
 * Features:
 * - Registers MongoDB connection factory
 * - Configures MongoDB as a database driver
 * - Sets up model connection resolver
 * - Publishes configuration files
 *
 * @package toporia/mongodb
 */
class MongoDBServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading is deferred.
     *
     * @var bool
     */
    protected bool $defer = true;

    /**
     * Register MongoDB services.
     *
     * @param ContainerInterface $container
     * @return void
     */
    public function register(ContainerInterface $container): void
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../config/mongodb.php',
            'mongodb',
            $container
        );

        // Register MongoDB connection factory
        $container->singleton('mongodb.connection', function ($c) {
            $config = $this->getMongoDBConfig($c);

            return new MongoDBConnection($config);
        });

        // Bind interface to implementation
        $container->bind(MongoDBConnectionInterface::class, 'mongodb.connection');

        // Register connection factory for multiple connections
        $container->singleton('mongodb.factory', function ($c) {
            return new MongoDBConnectionFactory($c);
        });
    }

    /**
     * Boot MongoDB services.
     *
     * @param ContainerInterface $container
     * @return void
     */
    public function boot(ContainerInterface $container): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/mongodb.php' => $this->configPath('mongodb.php'),
        ], 'mongodb-config');

        // Set up model connection resolver
        $this->setupModelResolver($container);

        // Register with DatabaseManager if available
        $this->extendDatabaseManager($container);
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [
            'mongodb.connection',
            'mongodb.factory',
            MongoDBConnectionInterface::class,
        ];
    }

    /**
     * Get MongoDB configuration.
     *
     * @param ContainerInterface $container
     * @return array<string, mixed>
     */
    protected function getMongoDBConfig(ContainerInterface $container): array
    {
        $config = $container->has('config') ? $container->get('config') : null;

        if ($config === null) {
            return $this->getDefaultConfig();
        }

        // Try to get from mongodb config
        $mongoConfig = method_exists($config, 'get') ? $config->get('mongodb', []) : [];

        if (!empty($mongoConfig)) {
            // Get the default connection
            $defaultConnection = $mongoConfig['default'] ?? 'mongodb';
            return $mongoConfig['connections'][$defaultConnection] ?? $this->getDefaultConfig();
        }

        // Try to get from database.connections.mongodb
        $dbConfig = method_exists($config, 'get') ? $config->get('database.connections.mongodb', []) : [];

        return !empty($dbConfig) ? $dbConfig : $this->getDefaultConfig();
    }

    /**
     * Get default MongoDB configuration.
     *
     * @return array<string, mixed>
     */
    protected function getDefaultConfig(): array
    {
        return [
            'driver' => 'mongodb',
            'host' => env('MONGODB_HOST', '127.0.0.1'),
            'port' => (int) env('MONGODB_PORT', 27017),
            'database' => env('MONGODB_DATABASE', 'toporia'),
            'username' => env('MONGODB_USERNAME'),
            'password' => env('MONGODB_PASSWORD'),
            'options' => [
                'authSource' => env('MONGODB_AUTH_SOURCE', 'admin'),
            ],
        ];
    }

    /**
     * Set up the model connection resolver.
     *
     * @param ContainerInterface $container
     * @return void
     */
    protected function setupModelResolver(ContainerInterface $container): void
    {
        if ($container->has('mongodb.connection')) {
            try {
                $connection = $container->get('mongodb.connection');
                MongoDBModel::setConnectionResolver($connection);
            } catch (\Throwable $e) {
                // Connection not available yet, will be set up later
            }
        }
    }

    /**
     * Extend DatabaseManager with MongoDB driver.
     *
     * @param ContainerInterface $container
     * @return void
     */
    protected function extendDatabaseManager(ContainerInterface $container): void
    {
        if (!$container->has('database')) {
            return;
        }

        try {
            $database = $container->get('database');

            if (method_exists($database, 'extend')) {
                $database->extend('mongodb', function ($config) {
                    return new MongoDBConnection($config);
                });
            }
        } catch (\Throwable $e) {
            // DatabaseManager not available
        }
    }

    /**
     * Get the configuration path.
     *
     * @param string $path
     * @return string
     */
    protected function configPath(string $path): string
    {
        return base_path('config/' . $path);
    }
}

/**
 * MongoDBConnectionFactory
 *
 * Factory for creating MongoDB connection instances.
 *
 * @package toporia/mongodb
 */
class MongoDBConnectionFactory
{
    /**
     * The container instance.
     *
     * @var ContainerInterface
     */
    protected ContainerInterface $container;

    /**
     * Cached connection instances.
     *
     * @var array<string, MongoDBConnection>
     */
    protected array $connections = [];

    /**
     * Create a new connection factory.
     *
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Get a MongoDB connection by name.
     *
     * @param string|null $name Connection name (null for default)
     * @return MongoDBConnection
     */
    public function connection(?string $name = null): MongoDBConnection
    {
        $name = $name ?? $this->getDefaultConnection();

        if (!isset($this->connections[$name])) {
            $this->connections[$name] = $this->makeConnection($name);
        }

        return $this->connections[$name];
    }

    /**
     * Create a new connection instance.
     *
     * @param string $name
     * @return MongoDBConnection
     */
    protected function makeConnection(string $name): MongoDBConnection
    {
        $config = $this->getConfig($name);

        return new MongoDBConnection($config);
    }

    /**
     * Get the configuration for a connection.
     *
     * @param string $name
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    protected function getConfig(string $name): array
    {
        $config = $this->container->has('config') ? $this->container->get('config') : null;

        if ($config === null) {
            throw new \InvalidArgumentException("MongoDB connection [{$name}] not configured.");
        }

        // Try mongodb config first
        $mongoConfig = method_exists($config, 'get') ? $config->get("mongodb.connections.{$name}") : null;

        if ($mongoConfig !== null) {
            return $mongoConfig;
        }

        // Try database config
        $dbConfig = method_exists($config, 'get') ? $config->get("database.connections.{$name}") : null;

        if ($dbConfig !== null) {
            return $dbConfig;
        }

        throw new \InvalidArgumentException("MongoDB connection [{$name}] not configured.");
    }

    /**
     * Get the default connection name.
     *
     * @return string
     */
    protected function getDefaultConnection(): string
    {
        $config = $this->container->has('config') ? $this->container->get('config') : null;

        if ($config !== null && method_exists($config, 'get')) {
            return $config->get('mongodb.default', 'mongodb');
        }

        return 'mongodb';
    }

    /**
     * Disconnect from the given connection.
     *
     * @param string|null $name
     * @return void
     */
    public function disconnect(?string $name = null): void
    {
        $name = $name ?? $this->getDefaultConnection();

        if (isset($this->connections[$name])) {
            $this->connections[$name]->disconnect();
            unset($this->connections[$name]);
        }
    }

    /**
     * Reconnect to the given connection.
     *
     * @param string|null $name
     * @return MongoDBConnection
     */
    public function reconnect(?string $name = null): MongoDBConnection
    {
        $name = $name ?? $this->getDefaultConnection();

        $this->disconnect($name);

        return $this->connection($name);
    }

    /**
     * Get all connection instances.
     *
     * @return array<string, MongoDBConnection>
     */
    public function getConnections(): array
    {
        return $this->connections;
    }
}
