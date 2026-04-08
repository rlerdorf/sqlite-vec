<?php

declare(strict_types=1);

namespace SqliteVec;

use InvalidArgumentException;
use RuntimeException;
use SQLite3;
use PDO;

final class SqliteVec
{
    public const VERSION = '0.1.9';

    private const PLATFORMS = [
        'Darwin' => [
            'x86_64'  => ['darwin-x86_64', 'vec0.dylib'],
            'arm64'   => ['darwin-aarch64', 'vec0.dylib'],
        ],
        'Linux' => [
            'x86_64'  => ['linux-x86_64', 'vec0.so'],
            'aarch64' => ['linux-aarch64', 'vec0.so'],
            'arm64'   => ['linux-aarch64', 'vec0.so'],
        ],
        'Windows' => [
            'AMD64'   => ['windows-x86_64', 'vec0.dll'],
            'x86_64'  => ['windows-x86_64', 'vec0.dll'],
        ],
    ];

    /**
     * Load sqlite-vec into a database connection.
     *
     * For Pdo\Sqlite (PHP 8.4+), no ini configuration is needed.
     * For SQLite3, sqlite3.extension_dir must be configured first
     * (run: vendor/bin/sqlite-vec-setup).
     */
    public static function load(SQLite3|PDO $db): void
    {
        $path = self::extensionPath();

        if ($db instanceof PDO) {
            self::loadPdo($db, $path);
        } else {
            self::loadSqlite3($db, $path);
        }
    }

    /**
     * Return the absolute path to the platform-specific binary.
     */
    public static function extensionPath(): string
    {
        [$dir, $file] = self::detectPlatform();
        $path = dirname(__DIR__) . '/ext/' . $dir . '/' . $file;

        if (!file_exists($path)) {
            throw new RuntimeException(
                "sqlite-vec binary not found at {$path}. "
                . "Your platform ({$dir}) may not be supported, or the package is incomplete."
            );
        }

        return realpath($path);
    }

    /**
     * Serialize a float array to the binary format sqlite-vec expects for float32 vectors.
     *
     * @param float[] $vector
     */
    public static function serializeFloat32(array $vector): string
    {
        if ($vector === []) {
            throw new InvalidArgumentException('Vector must not be empty.');
        }
        return pack('f*', ...$vector);
    }

    /**
     * Deserialize a binary float32 vector back to a PHP array.
     *
     * @return float[]
     */
    public static function deserializeFloat32(string $binary): array
    {
        if (strlen($binary) === 0 || strlen($binary) % 4 !== 0) {
            throw new InvalidArgumentException(
                'Binary string must be non-empty and a multiple of 4 bytes.'
            );
        }
        return array_values(unpack('f*', $binary));
    }

    /**
     * Serialize an int array to the binary format sqlite-vec expects for int8 vectors.
     *
     * @param int[] $vector Values in [-128, 127]
     */
    public static function serializeInt8(array $vector): string
    {
        if ($vector === []) {
            throw new InvalidArgumentException('Vector must not be empty.');
        }
        return pack('c*', ...$vector);
    }

    /**
     * Deserialize a binary int8 vector back to a PHP array.
     *
     * @return int[]
     */
    public static function deserializeInt8(string $binary): array
    {
        if (strlen($binary) === 0) {
            throw new InvalidArgumentException('Binary string must not be empty.');
        }
        return array_values(unpack('c*', $binary));
    }

    /**
     * Query the loaded sqlite-vec version from a database connection.
     */
    public static function vecVersion(SQLite3|PDO $db): string
    {
        if ($db instanceof PDO) {
            return $db->query('SELECT vec_version()')->fetchColumn();
        }
        return $db->querySingle('SELECT vec_version()');
    }

    // ---

    /** @return array{string, string} [directory, filename] */
    private static function detectPlatform(): array
    {
        $os = PHP_OS_FAMILY;
        $machine = php_uname('m');

        if (!isset(self::PLATFORMS[$os][$machine])) {
            throw new RuntimeException(
                "Unsupported platform: {$os}/{$machine}. "
                . 'Supported: linux-x86_64, linux-aarch64, darwin-x86_64, darwin-aarch64, windows-x86_64.'
            );
        }

        return self::PLATFORMS[$os][$machine];
    }

    private static function loadPdo(PDO $db, string $extensionPath): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'sqlite') {
            throw new InvalidArgumentException(
                "Expected a SQLite PDO connection, got '{$driver}'."
            );
        }

        if ($db instanceof \Pdo\Sqlite) {
            $db->loadExtension($extensionPath);
            return;
        }

        if (method_exists($db, 'loadExtension')) {
            $db->loadExtension($extensionPath);
            return;
        }

        throw new RuntimeException(
            'PDO extension loading requires PHP 8.4+ with Pdo\\Sqlite. '
            . 'Use the SQLite3 class instead, or upgrade PHP.'
        );
    }

    private static function loadSqlite3(SQLite3 $db, string $extensionPath): void
    {
        $extensionDir = ini_get('sqlite3.extension_dir');

        if ($extensionDir === '' || $extensionDir === false) {
            throw new RuntimeException(
                "sqlite3.extension_dir is not configured.\n"
                . "Run:  sudo vendor/bin/sqlite-vec-setup\n"
                . "Or add to php.ini:  sqlite3.extension_dir = " . dirname($extensionPath) . "\n"
                . 'Or use Pdo\\Sqlite on PHP 8.4+ (no ini config needed).'
            );
        }

        $filename = basename($extensionPath);
        $result = @$db->loadExtension($filename);

        if (!$result) {
            $actualDir = realpath($extensionDir) ?: $extensionDir;
            $expectedDir = dirname($extensionPath);

            if ($actualDir !== $expectedDir) {
                throw new RuntimeException(
                    "Failed to load sqlite-vec. sqlite3.extension_dir is '{$extensionDir}' "
                    . "but the bundled binary is in '{$expectedDir}'.\n"
                    . "Run:  sudo vendor/bin/sqlite-vec-setup\n"
                    . "Or copy the binary:  cp {$extensionPath} {$actualDir}/"
                );
            }

            throw new RuntimeException(
                "Failed to load sqlite-vec from '{$extensionDir}/{$filename}'. "
                . 'Verify the binary is compatible with your system.'
            );
        }
    }
}
