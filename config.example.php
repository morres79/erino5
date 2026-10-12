<?php
// Copy OUTSIDE the public website directory. Set ERINO_CONFIG to its absolute path.
// Never commit real credentials. The schema must exist before using the application.
return [
    'dsn' => 'mysql:host=195.189.181.72;port=3306;dbname=erino5;charset=utf8mb4',
    'user' => 'REPLACE_WITH_DATABASE_USER',
    'password' => 'REPLACE_WITH_DATABASE_PASSWORD',
    // For an external database, use the provider's CA file to verify TLS.
    // 'ssl_ca' => '/absolute/path/to/database-ca.pem',
];