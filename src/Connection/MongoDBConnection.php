<?php

declare(strict_types=1);

namespace Toporia\MongoDB\Connection;

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Session;
use MongoDB\Driver\Exception\RuntimeException as MongoRuntimeException;
use Toporia\MongoDB\Contracts\MongoDBConnectionInterface;
use Toporia\MongoDB\Query\MongoDBQueryBuilder;

/**
 * MongoDB Connection
 *
 * Provides connection management for MongoDB databases.
 * Wraps the official MongoDB PHP library client.
 *
 * Features:
 * - Lazy connection initialization
 * - Connection pooling (handled by MongoDB driver)
 * - Transaction support (MongoDB 4.0+ replica sets)
 * - SSL/TLS support
 * - Replica set support
 * - Authentication support
 *
 * Design Patterns:
 * - Adapter Pattern: Adapts MongoDB\Client to framework interface
 * - Lazy Initialization: Connection created on first use
 * - Factory Method: Creates query builders for collections
 *
 * SOLID Principles:
 * - Single Responsibility: Connection management only
 * - Open/Closed: Extendable via inheritance
 * - Dependency Inversion: Implements interface contract
 *
 * Performance:
 * - Connection pooling managed by MongoDB driver
 * - Lazy connection prevents unnecessary connections
 * - Session reuse for transactions
 *
 * @package toporia/mongodb
 * @author Phungtruong7820 <minhphung485@gmail.com>
 * @since 1.0.0
 */
class MongoDBConnection implements MongoDBConnectionInterface
{
    /**
     * MongoDB Client instance.
     */
    private ?Client $client = null;

    /**
     * Current database instance.
     */
    private ?Database $database = null;

    /**
     * Connection configuration.
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * Current transaction session.
     */
    private ?Session $session = null;

    /**
     * Transaction nesting level.
     */
    private int $transactionLevel = 0;

    /**
     * Type map for BSON documents.
     *
     * @var array<string, string>
     */
    private array $typeMap = [
        'root' => 'array',
        'document' => 'array',
        'array' => 'array',
    ];

    /**
     * Create a new MongoDB connection.
     *
     * @param array<string, mixed> $config Connection configuration
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        // Override type map if provided in config
        if (isset($config['type_map'])) {
            $this->typeMap = array_merge($this->typeMap, $config['type_map']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getClient(): Client
    {
        if ($this->client === null) {
            $this->connect();
        }

        return $this->client;
    }

    /**
     * {@inheritdoc}
     */
    public function getDatabase(): Database
    {
        if ($this->database === null) {
            $this->database = $this->getClient()->selectDatabase(
                $this->getDatabaseName(),
                ['typeMap' => $this->typeMap]
            );
        }

        return $this->database;
    }

    /**
     * {@inheritdoc}
     */
    public function collection(string $name): Collection
    {
        return $this->getDatabase()->selectCollection($name, [
            'typeMap' => $this->typeMap,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * {@inheritdoc}
     */
    public function getDatabaseName(): string
    {
        return $this->config['database'] ?? 'toporia';
    }

    /**
     * {@inheritdoc}
     */
    public function getDriverName(): string
    {
        return 'mongodb';
    }

    /**
     * {@inheritdoc}
     */
    public function table(string $collection): MongoDBQueryBuilder
    {
        return new MongoDBQueryBuilder($this, $collection);
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction(): bool
    {
        // MongoDB transactions require replica set
        if ($this->transactionLevel === 0) {
            $this->session = $this->getClient()->startSession();
            $this->session->startTransaction();
        }

        $this->transactionLevel++;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): bool
    {
        if ($this->transactionLevel === 0) {
            throw new \RuntimeException('No active transaction to commit.');
        }

        $this->transactionLevel--;

        if ($this->transactionLevel === 0 && $this->session !== null) {
            $this->session->commitTransaction();
            $this->session->endSession();
            $this->session = null;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function rollback(): bool
    {
        if ($this->transactionLevel === 0) {
            throw new \RuntimeException('No active transaction to rollback.');
        }

        $this->transactionLevel = 0;

        if ($this->session !== null) {
            $this->session->abortTransaction();
            $this->session->endSession();
            $this->session = null;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function transaction(callable $callback, int $attempts = 1): mixed
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->beginTransaction();

            try {
                $result = $callback($this);
                $this->commit();

                return $result;
            } catch (MongoRuntimeException $e) {
                $this->rollback();

                // Retry on transient transaction errors
                if ($this->isTransientError($e) && $attempt < $attempts) {
                    usleep(100000 * $attempt); // Exponential backoff
                    continue;
                }

                throw $e;
            } catch (\Throwable $e) {
                $this->rollback();
                throw $e;
            }
        }

        throw new \RuntimeException('Transaction failed after ' . $attempts . ' attempts.');
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): void
    {
        $this->client = null;
        $this->database = null;
        $this->session = null;
        $this->transactionLevel = 0;
    }

    /**
     * {@inheritdoc}
     */
    public function reconnect(): void
    {
        $this->disconnect();
        $this->connect();
    }

    /**
     * {@inheritdoc}
     */
    public function ping(): bool
    {
        try {
            $result = $this->command(['ping' => 1]);
            return isset($result['ok']) && $result['ok'] == 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getServerInfo(): array
    {
        return $this->command(['buildInfo' => 1]);
    }

    /**
     * {@inheritdoc}
     */
    public function listCollections(): array
    {
        $collections = [];

        foreach ($this->getDatabase()->listCollections() as $collection) {
            $collections[] = $collection->getName();
        }

        return $collections;
    }

    /**
     * {@inheritdoc}
     */
    public function dropCollection(string $name): bool
    {
        $result = $this->getDatabase()->dropCollection($name);
        return $result->isAcknowledged();
    }

    /**
     * {@inheritdoc}
     */
    public function createCollection(string $name, array $options = []): bool
    {
        $result = $this->getDatabase()->createCollection($name, $options);
        return $result->isAcknowledged();
    }

    /**
     * {@inheritdoc}
     */
    public function command(array $command): array
    {
        $cursor = $this->getDatabase()->command($command);
        $result = $cursor->toArray();

        return $result[0] ?? [];
    }

    /**
     * Get the current session for transaction operations.
     *
     * @return Session|null Current session or null if not in transaction
     */
    public function getSession(): ?Session
    {
        return $this->session;
    }

    /**
     * Establish the database connection.
     *
     * @return void
     */
    protected function connect(): void
    {
        $uri = $this->buildConnectionUri();
        $options = $this->buildConnectionOptions();
        $driverOptions = $this->buildDriverOptions();

        $this->client = new Client($uri, $options, $driverOptions);
    }

    /**
     * Build the MongoDB connection URI.
     *
     * Supports:
     * - Direct DSN string
     * - Single host configuration
     * - Multiple hosts (replica set)
     *
     * @return string MongoDB connection URI
     */
    protected function buildConnectionUri(): string
    {
        // Check for direct DSN
        if (isset($this->config['dsn'])) {
            return $this->config['dsn'];
        }

        // Build from components
        $scheme = 'mongodb://';

        // Check for SRV record (mongodb+srv://)
        if (isset($this->config['srv']) && $this->config['srv']) {
            $scheme = 'mongodb+srv://';
        }

        // Build authentication part
        $auth = '';
        if (!empty($this->config['username']) && !empty($this->config['password'])) {
            $auth = urlencode($this->config['username']) . ':' . urlencode($this->config['password']) . '@';
        }

        // Build host(s) part
        $hosts = $this->buildHostsString();

        // Build options query string
        $queryParams = $this->buildQueryParams();
        $query = !empty($queryParams) ? '?' . http_build_query($queryParams) : '';

        return $scheme . $auth . $hosts . '/' . $query;
    }

    /**
     * Build the hosts string for the connection URI.
     *
     * @return string Hosts string (host:port,host:port,...)
     */
    protected function buildHostsString(): string
    {
        // Multiple hosts (replica set)
        if (isset($this->config['hosts']) && is_array($this->config['hosts'])) {
            $hostStrings = [];
            foreach ($this->config['hosts'] as $hostConfig) {
                $host = $hostConfig['host'] ?? 'localhost';
                $port = $hostConfig['port'] ?? 27017;
                $hostStrings[] = $host . ':' . $port;
            }
            return implode(',', $hostStrings);
        }

        // Single host
        $host = $this->config['host'] ?? 'localhost';
        $port = $this->config['port'] ?? 27017;

        return $host . ':' . $port;
    }

    /**
     * Build query parameters for connection URI.
     *
     * @return array<string, mixed> Query parameters
     */
    protected function buildQueryParams(): array
    {
        $params = [];

        // Authentication database
        if (!empty($this->config['auth_database'])) {
            $params['authSource'] = $this->config['auth_database'];
        }

        // Authentication mechanism
        if (!empty($this->config['auth_mechanism'])) {
            $params['authMechanism'] = $this->config['auth_mechanism'];
        }

        // Replica set
        if (!empty($this->config['options']['replicaSet'])) {
            $params['replicaSet'] = $this->config['options']['replicaSet'];
        }

        return $params;
    }

    /**
     * Build connection options array.
     *
     * @return array<string, mixed> Connection options
     */
    protected function buildConnectionOptions(): array
    {
        $options = $this->config['options'] ?? [];

        // SSL/TLS configuration
        if (isset($this->config['ssl']['enabled']) && $this->config['ssl']['enabled']) {
            $options['ssl'] = true;

            if (!empty($this->config['ssl']['allow_invalid_certificates'])) {
                $options['tlsAllowInvalidCertificates'] = true;
            }

            if (!empty($this->config['ssl']['ca_file'])) {
                $options['tlsCAFile'] = $this->config['ssl']['ca_file'];
            }

            if (!empty($this->config['ssl']['cert_file'])) {
                $options['tlsCertificateKeyFile'] = $this->config['ssl']['cert_file'];
            }

            if (!empty($this->config['ssl']['key_password'])) {
                $options['tlsCertificateKeyFilePassword'] = $this->config['ssl']['key_password'];
            }
        }

        return $options;
    }

    /**
     * Build driver options array.
     *
     * @return array<string, mixed> Driver options
     */
    protected function buildDriverOptions(): array
    {
        return [
            'typeMap' => $this->typeMap,
        ];
    }

    /**
     * Check if an exception is a transient transaction error.
     *
     * @param MongoRuntimeException $e Exception to check
     * @return bool True if transient error
     */
    protected function isTransientError(MongoRuntimeException $e): bool
    {
        // Check for transient transaction error label
        if (method_exists($e, 'hasErrorLabel')) {
            return $e->hasErrorLabel('TransientTransactionError');
        }

        return false;
    }
}
