<?php

declare(strict_types=1);

namespace SqliteVec\Tests;

use PHPUnit\Framework\TestCase;
use SqliteVec\SqliteVec;
use InvalidArgumentException;

class SqliteVecTest extends TestCase
{
    // --- Serialization ---

    public function testSerializeFloat32(): void
    {
        $packed = SqliteVec::serializeFloat32([1.0, 2.0, 3.0]);
        $this->assertSame(12, strlen($packed));
    }

    public function testSerializeFloat32Roundtrip(): void
    {
        $original = [1.0, -2.5, 3.14, 0.0, -0.001];
        $packed = SqliteVec::serializeFloat32($original);
        $unpacked = SqliteVec::deserializeFloat32($packed);

        foreach ($original as $i => $expected) {
            $this->assertEqualsWithDelta($expected, $unpacked[$i], 1e-6);
        }
    }

    public function testSerializeFloat32Empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SqliteVec::serializeFloat32([]);
    }

    public function testDeserializeFloat32BadLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SqliteVec::deserializeFloat32('abc');
    }

    public function testSerializeInt8(): void
    {
        $packed = SqliteVec::serializeInt8([1, -1, 127, -128, 0]);
        $this->assertSame(5, strlen($packed));
    }

    public function testSerializeInt8Roundtrip(): void
    {
        $original = [1, -1, 127, -128, 0, 42];
        $packed = SqliteVec::serializeInt8($original);
        $unpacked = SqliteVec::deserializeInt8($packed);
        $this->assertSame($original, $unpacked);
    }

    public function testSerializeInt8Empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SqliteVec::serializeInt8([]);
    }

    public function testSerializeInt8OutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be in [-128, 127]');
        SqliteVec::serializeInt8([0, 128]);
    }

    public function testDeserializeInt8Empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SqliteVec::deserializeInt8('');
    }

    // --- Extension path ---

    public function testExtensionPathExists(): void
    {
        $path = SqliteVec::extensionPath();
        $this->assertFileExists($path);
    }

    public function testExtensionPathIsAbsolute(): void
    {
        $path = SqliteVec::extensionPath();
        $this->assertTrue(
            str_starts_with($path, '/') || preg_match('/^[A-Z]:\\\\/', $path) === 1
        );
    }

    // --- Integration (requires extension loading) ---

    /** @group integration */
    public function testLoadPdoSqlite(): void
    {
        if (PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('Pdo\\Sqlite::loadExtension requires PHP 8.4+');
        }
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('ext-pdo_sqlite not available');
        }

        $db = new \Pdo\Sqlite('sqlite::memory:');
        SqliteVec::load($db);

        $version = SqliteVec::vecVersion($db);
        $this->assertStringStartsWith('v', $version);
    }

    /** @group integration */
    public function testLoadSqlite3(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('ext-sqlite3 not available');
        }

        $dir = ini_get('sqlite3.extension_dir');
        if ($dir === '' || $dir === false) {
            $this->markTestSkipped('sqlite3.extension_dir not configured');
        }

        $db = new \SQLite3(':memory:');
        SqliteVec::load($db);

        $version = SqliteVec::vecVersion($db);
        $this->assertStringStartsWith('v', $version);
        $db->close();
    }

    /** @group integration */
    public function testVectorSearchRoundtrip(): void
    {
        if (PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('Pdo\\Sqlite::loadExtension requires PHP 8.4+');
        }
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('ext-pdo_sqlite not available');
        }

        $db = new \Pdo\Sqlite('sqlite::memory:');
        SqliteVec::load($db);

        $db->exec('CREATE VIRTUAL TABLE vec_items USING vec0(embedding float[4])');

        $stmt = $db->prepare('INSERT INTO vec_items(rowid, embedding) VALUES (:id, :emb)');
        $vectors = [
            1 => [1.0, 0.0, 0.0, 0.0],
            2 => [0.0, 1.0, 0.0, 0.0],
            3 => [0.0, 0.0, 1.0, 0.0],
        ];
        foreach ($vectors as $id => $vec) {
            $blob = SqliteVec::serializeFloat32($vec);
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->bindValue(':emb', $blob, \PDO::PARAM_LOB);
            $stmt->execute();
        }

        // KNN query: find nearest to [1, 0.1, 0, 0] — should be rowid 1
        $query = SqliteVec::serializeFloat32([1.0, 0.1, 0.0, 0.0]);
        $stmt = $db->prepare(
            'SELECT rowid, distance FROM vec_items WHERE embedding MATCH :q ORDER BY distance LIMIT 1'
        );
        $stmt->bindValue(':q', $query, \PDO::PARAM_LOB);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame(1, (int) $row['rowid']);
        $this->assertLessThan(0.2, (float) $row['distance']);
    }
}
