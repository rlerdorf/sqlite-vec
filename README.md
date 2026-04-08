# sqlite-vec for PHP

PHP bindings for [sqlite-vec](https://github.com/asg017/sqlite-vec) — a vector search extension for SQLite.

Ships pre-built binaries for Linux (x86_64, aarch64), macOS (x86_64, Apple Silicon), and Windows (x86_64). No compilation needed.

## Requirements

- PHP 8.1+
- `ext-pdo_sqlite` (recommended, zero-config on PHP 8.4+) or `ext-sqlite3`

## Installation

```bash
composer require sqlite-vec/sqlite-vec
```

### SQLite3 class users

The `SQLite3` class requires `sqlite3.extension_dir` to be set in php.ini. A setup script is included:

```bash
sudo vendor/bin/sqlite-vec-setup
```

This detects your PHP ini scan directories and writes a config file pointing `sqlite3.extension_dir` to the bundled binary. Run with `--dry-run` to preview, `--status` to check current config, or `--remove` to uninstall.

`Pdo\Sqlite` on PHP 8.4+ does **not** need this step.

## Usage

### Load the extension

```php
use SqliteVec\SqliteVec;

// PDO (PHP 8.4+) — zero config
$db = new \Pdo\Sqlite('sqlite::memory:');
SqliteVec::load($db);

// SQLite3 — requires sqlite-vec-setup first
$db = new \SQLite3(':memory:');
SqliteVec::load($db);
```

### Vector search

```php
$db = new \Pdo\Sqlite('sqlite::memory:');
SqliteVec::load($db);

// Create a vector table (4-dimensional float vectors)
$db->exec('CREATE VIRTUAL TABLE vec_items USING vec0(embedding float[4])');

// Insert vectors
$stmt = $db->prepare('INSERT INTO vec_items(rowid, embedding) VALUES (:id, :emb)');
$vectors = [
    1 => [1.0, 0.0, 0.0, 0.0],
    2 => [0.0, 1.0, 0.0, 0.0],
    3 => [0.0, 0.0, 1.0, 0.0],
];
foreach ($vectors as $id => $vec) {
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->bindValue(':emb', SqliteVec::serializeFloat32($vec), PDO::PARAM_LOB);
    $stmt->execute();
}

// KNN query — find the nearest vector to [1.0, 0.1, 0.0, 0.0]
$query = SqliteVec::serializeFloat32([1.0, 0.1, 0.0, 0.0]);
$stmt = $db->prepare(
    'SELECT rowid, distance FROM vec_items WHERE embedding MATCH :q ORDER BY distance LIMIT 3'
);
$stmt->bindValue(':q', $query, PDO::PARAM_LOB);
$stmt->execute();

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "rowid={$row['rowid']} distance={$row['distance']}\n";
}
```

### Serialization helpers

```php
// Float32 vectors
$binary = SqliteVec::serializeFloat32([1.0, 2.0, 3.0]);
$array  = SqliteVec::deserializeFloat32($binary);

// Int8 vectors (values in [-128, 127])
$binary = SqliteVec::serializeInt8([1, -1, 127, -128]);
$array  = SqliteVec::deserializeInt8($binary);
```

### Check version

```php
$version = SqliteVec::vecVersion($db); // e.g. "v0.1.9"
```

## API

### `SqliteVec::load(SQLite3|PDO $db): void`

Load the sqlite-vec extension into a database connection.

### `SqliteVec::extensionPath(): string`

Return the absolute path to the platform-specific binary.

### `SqliteVec::serializeFloat32(array $vector): string`

Pack a float array into the binary format sqlite-vec expects.

### `SqliteVec::deserializeFloat32(string $binary): array`

Unpack a binary float32 vector back to a PHP array.

### `SqliteVec::serializeInt8(array $vector): string`

Pack an int array (values -128..127) into binary int8 format.

### `SqliteVec::deserializeInt8(string $binary): array`

Unpack a binary int8 vector back to a PHP array.

### `SqliteVec::vecVersion(SQLite3|PDO $db): string`

Query the loaded sqlite-vec version string.

## Bundled sqlite-vec version

This package bundles sqlite-vec **v0.1.9**. The pre-built binaries are distributed under the same MIT license as sqlite-vec itself.

## License

MIT — see [LICENSE](LICENSE).
