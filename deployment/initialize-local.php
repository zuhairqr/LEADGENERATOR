<?php
/** One-time setup for the dedicated, loopback-only MariaDB instance on port 3310. */
declare(strict_types=1);

$root = dirname(__DIR__);
$envPath = $root.'/.env';
$adminPath = $root.'/storage/local-mysql-admin.cnf';
if (PHP_SAPI !== 'cli' || is_file($envPath) || is_file($adminPath)) {
    fwrite(STDERR, "Initialization requires CLI and a fresh local setup; existing configuration was preserved.\n");
    exit(1);
}
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3310;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // Only this freshly initialized project server is used. Never use the shared XAMPP data directory.
    $dataDir = str_replace('\\', '/', (string) $pdo->query('SELECT @@datadir')->fetchColumn());
    if (strcasecmp(rtrim($dataDir, '/'), str_replace('\\', '/', $root).'/storage/local-mysql') !== 0) {
        throw new RuntimeException('Unexpected database server.');
    }
    $password = bin2hex(random_bytes(32));
    $rootPassword = bin2hex(random_bytes(32));
    foreach (['lead_generator_local', 'lead_generator_test'] as $database) {
        $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    foreach (['localhost', '127.0.0.1'] as $host) {
        $pdo->exec("CREATE USER 'lead_generator_local'@'$host' IDENTIFIED BY ".$pdo->quote($password));
        foreach (['lead_generator_local', 'lead_generator_test'] as $database) {
            $pdo->exec("GRANT ALL PRIVILEGES ON `$database`.* TO 'lead_generator_local'@'$host'");
        }
    }
    $env = "APP_NAME=\"Lead Generator\"\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=false\nAPP_URL=http://127.0.0.1:8079\n";
    $env .= "DB_HOST=127.0.0.1\nDB_PORT=3310\nDB_DATABASE=lead_generator_local\nDB_USERNAME=lead_generator_local\nDB_PASSWORD=$password\n";
    $env .= "SESSION_SECURE_COOKIE=false\nLEAD_ADMIN_EMAIL=\nLEAD_ADMIN_PASSWORD_HASH=''\nLEAD_IMPORT_TOKEN=\n";
    $env .= "OPENAI_API_KEY=\nOPENAI_MODEL=gpt-4.1-mini\nLEAD_VERIFIED_PORTFOLIO=''\nLEAD_REMOTIVE_APPROVED=false\n";
    $handle = fopen($envPath, 'x');
    if (!$handle) throw new RuntimeException('Cannot create local environment.');
    fwrite($handle, $env);
    fclose($handle);
    // Keep administrative access private; it is not needed by the web application.
    file_put_contents($adminPath, "[client]\nhost=127.0.0.1\nport=3310\nuser=root\npassword=$rootPassword\n");
    $hosts = $pdo->query("SELECT Host FROM mysql.user WHERE User='root'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($hosts as $host) {
        $pdo->exec('ALTER USER \'root\'@'.$pdo->quote($host).' IDENTIFIED BY '.$pdo->quote($rootPassword));
    }
    echo "Dedicated local and test databases created; private configuration saved. No credential values displayed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Local initialization failed; inspect local server state before retrying. No credentials displayed.\n");
    exit(1);
}
