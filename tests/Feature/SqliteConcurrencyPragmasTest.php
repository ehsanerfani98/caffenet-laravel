<?php

namespace Tests\Feature;

use Illuminate\Database\Connectors\SQLiteConnector;
use PDO;
use Tests\TestCase;

/**
 * SQLite concurrency hardening (queue worker + web + scheduler share one file).
 *
 * Guards:
 *  - config ships production-safe defaults (busy_timeout / WAL / synchronous / IMMEDIATE)
 *  - the connector really applies those pragmas on a file-based connection
 *  - busy_timeout turns the former instant "database is locked" (SQLITE_BUSY)
 *    failure into a wait: a writer blocked by an uncommitted transaction now
 *    waits for the timeout window instead of throwing immediately
 *
 * Production incident: DatabaseQueue::deleteReserved() crashed with
 * "General error: 5 database is locked" because busy_timeout was unset (0).
 */
class SqliteConcurrencyPragmasTest extends TestCase
{
    public function test_config_ships_concurrency_hardening_defaults(): void
    {
        $sqlite = config('database.connections.sqlite');

        $this->assertSame(10000, (int) $sqlite['busy_timeout']);
        $this->assertSame('wal', strtolower((string) $sqlite['journal_mode']));
        $this->assertSame('normal', strtolower((string) $sqlite['synchronous']));
        $this->assertSame('IMMEDIATE', strtoupper((string) $sqlite['transaction_mode']));
    }

    public function test_connector_applies_pragmas_on_file_database(): void
    {
        $path = sys_get_temp_dir().'/caffenet-pragma-test-'.getmypid().'.sqlite';
        @unlink($path);
        touch($path);

        $pdo = (new SQLiteConnector)->connect($this->fileConfig($path));

        $this->assertSame('wal', strtolower((string) $pdo->query('pragma journal_mode')->fetchColumn()));
        $this->assertSame(10000, (int) $pdo->query('pragma busy_timeout')->fetchColumn());
        // synchronous: 1 = NORMAL (recommended pairing with WAL)
        $this->assertSame(1, (int) $pdo->query('pragma synchronous')->fetchColumn());

        $pdo = null;
        @unlink($path);
        @unlink($path.'-wal');
        @unlink($path.'-shm');
    }

    public function test_busy_timeout_waits_instead_of_instant_locked_failure(): void
    {
        $path = sys_get_temp_dir().'/caffenet-busy-test-'.getmypid().'.sqlite';
        @unlink($path);
        touch($path);

        $config = $this->fileConfig($path);
        $holder = (new SQLiteConnector)->connect($config);
        $holder->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, payload TEXT)');
        $holder->exec('INSERT INTO jobs (payload) VALUES (\'x\')');

        // Writer A takes the write lock and holds it (uncommitted).
        $holder->exec('BEGIN IMMEDIATE TRANSACTION');
        $holder->exec('UPDATE jobs SET payload = \'held\'');

        // Writer B must wait inside the busy window, not fail instantly.
        $started = microtime(true);
        try {
            $contender = (new SQLiteConnector)->connect($config);
            // Shorter timeout than the hold time below -> still ends in BUSY,
            // but only after waiting the full window (elapsed >= timeout).
            $contender->exec('PRAGMA busy_timeout = 1500');
            $contender->exec('DELETE FROM jobs');
            $this->fail('Expected the blocked writer to exhaust the busy window.');
        } catch (\PDOException $e) {
            $elapsed = microtime(true) - $started;
            $this->assertGreaterThanOrEqual(1.4, $elapsed,
                'busy_timeout was not applied — the write failed instantly.');
            $this->assertStringContainsStringIgnoringCase('locked', $e->getMessage());
        } finally {
            $holder->exec('ROLLBACK');
            $holder = null;
            $contender = null;
        }

        // After the holder releases, a normal writer succeeds immediately.
        $final = (new SQLiteConnector)->connect($config);
        $final->exec('DELETE FROM jobs');
        $this->assertSame(0, (int) $final->query('SELECT COUNT(*) FROM jobs')->fetchColumn());

        $final = null;
        @unlink($path);
        @unlink($path.'-wal');
        @unlink($path.'-shm');
    }

    public function test_wal_allows_reader_while_writer_holds_lock(): void
    {
        $path = sys_get_temp_dir().'/caffenet-wal-test-'.getmypid().'.sqlite';
        @unlink($path);
        touch($path);

        $config = $this->fileConfig($path);
        $writer = (new SQLiteConnector)->connect($config);
        $writer->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, payload TEXT)');
        $writer->exec('INSERT INTO jobs (payload) VALUES (\'visible\')');

        // Uncommitted write must not block readers under WAL.
        $writer->exec('BEGIN IMMEDIATE TRANSACTION');
        $writer->exec('UPDATE jobs SET payload = \'held\'');

        $reader = (new SQLiteConnector)->connect($config);
        $this->assertSame(
            'visible',
            (string) $reader->query('SELECT payload FROM jobs LIMIT 1')->fetchColumn(),
            'WAL should let readers see the pre-transaction snapshot.'
        );

        $writer->exec('ROLLBACK');
        $writer = null;
        $reader = null;
        @unlink($path);
        @unlink($path.'-wal');
        @unlink($path.'-shm');
    }

    private function fileConfig(string $path): array
    {
        return array_merge(config('database.connections.sqlite'), [
            'database' => $path,
            'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        ]);
    }
}
