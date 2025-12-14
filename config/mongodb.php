<?php

declare(strict_types=1);

/**
 * MongoDB Configuration
 *
 * This file contains the default configuration for MongoDB connections.
 * You can override these settings in your application's config/database.php
 * under the 'connections' key with driver 'mongodb'.
 *
 * @package toporia/mongodb
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Default MongoDB Connection
    |--------------------------------------------------------------------------
    |
    | The default MongoDB connection name to use when no connection is
    | explicitly specified. This should match a key in the connections array.
    |
    */
    'default' => env('MONGODB_CONNECTION', 'mongodb'),

    /*
    |--------------------------------------------------------------------------
    | MongoDB Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each MongoDB
    | server that is used by your application. A default configuration has
    | been provided as an example.
    |
    | Supported authentication mechanisms:
    | - SCRAM-SHA-1 (default for MongoDB 3.0+)
    | - SCRAM-SHA-256 (MongoDB 4.0+)
    | - MONGODB-X509 (certificate-based)
    | - GSSAPI (Kerberos)
    | - PLAIN (LDAP)
    |
    */
    'connections' => [
        'mongodb' => [
            'driver' => 'mongodb',
            'host' => env('MONGODB_HOST', 'localhost'),
            'port' => env('MONGODB_PORT', 27017),
            'database' => env('MONGODB_DATABASE', 'toporia'),
            'username' => env('MONGODB_USERNAME'),
            'password' => env('MONGODB_PASSWORD'),

            // Authentication database (usually 'admin')
            'auth_database' => env('MONGODB_AUTH_DATABASE', 'admin'),

            // Authentication mechanism (null = auto-detect)
            'auth_mechanism' => env('MONGODB_AUTH_MECHANISM'),

            // Connection options
            'options' => [
                // Application name for server logs
                'appname' => env('APP_NAME', 'Toporia'),

                // Read preference: primary, primaryPreferred, secondary, secondaryPreferred, nearest
                'readPreference' => 'primary',

                // Write concern: 0, 1, 'majority'
                'w' => 'majority',

                // Write concern timeout in milliseconds
                'wTimeoutMS' => 10000,

                // Connection timeout in milliseconds
                'connectTimeoutMS' => 10000,

                // Socket timeout in milliseconds
                'socketTimeoutMS' => 30000,

                // Server selection timeout in milliseconds
                'serverSelectionTimeoutMS' => 30000,

                // Retry reads on network errors
                'retryReads' => true,

                // Retry writes on network errors (MongoDB 3.6+)
                'retryWrites' => true,
            ],

            // SSL/TLS configuration
            'ssl' => [
                'enabled' => env('MONGODB_SSL', false),
                'allow_invalid_certificates' => env('MONGODB_SSL_ALLOW_INVALID', false),
                'ca_file' => env('MONGODB_SSL_CA_FILE'),
                'cert_file' => env('MONGODB_SSL_CERT_FILE'),
                'key_file' => env('MONGODB_SSL_KEY_FILE'),
                'key_password' => env('MONGODB_SSL_KEY_PASSWORD'),
            ],
        ],

        // Example: Replica set configuration
        // 'replica' => [
        //     'driver' => 'mongodb',
        //     'hosts' => [
        //         ['host' => 'mongo1.example.com', 'port' => 27017],
        //         ['host' => 'mongo2.example.com', 'port' => 27017],
        //         ['host' => 'mongo3.example.com', 'port' => 27017],
        //     ],
        //     'database' => env('MONGODB_DATABASE', 'toporia'),
        //     'username' => env('MONGODB_USERNAME'),
        //     'password' => env('MONGODB_PASSWORD'),
        //     'options' => [
        //         'replicaSet' => 'rs0',
        //         'readPreference' => 'secondaryPreferred',
        //     ],
        // ],

        // Example: MongoDB Atlas (cloud) configuration
        // 'atlas' => [
        //     'driver' => 'mongodb',
        //     'dsn' => env('MONGODB_DSN'), // mongodb+srv://user:pass@cluster.mongodb.net/database
        //     'database' => env('MONGODB_DATABASE', 'toporia'),
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MongoDB Type Mapping
    |--------------------------------------------------------------------------
    |
    | Configure how PHP types are mapped to MongoDB BSON types.
    |
    */
    'type_map' => [
        'root' => 'array',
        'document' => 'array',
        'array' => 'array',
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto Index Creation
    |--------------------------------------------------------------------------
    |
    | When enabled, indexes defined in models will be automatically created
    | when the model is first used. Disable in production for performance.
    |
    */
    'auto_index' => env('MONGODB_AUTO_INDEX', false),

    /*
    |--------------------------------------------------------------------------
    | Query Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, all MongoDB queries will be logged for debugging.
    | This should be disabled in production for performance.
    |
    */
    'query_log' => env('MONGODB_QUERY_LOG', env('APP_DEBUG', false)),
];
