<?php

namespace Tusharb\EnvCrypt\Tests;

use Tusharb\EnvCrypt\EnvFile;

/**
 * Backups hold every plaintext password a project has, so where they land and
 * whether they can be clobbered are security properties, not conveniences.
 */
class EnvBackupTest extends TestCase
{
    public function test_it_writes_outside_the_public_web_root()
    {
        $backup = $this->backups()->create('encrypt');

        $this->assertNotNull($backup);
        $this->assertFileExists($backup);
        $this->assertStringContainsString('storage/app/envcrypt-backups', str_replace('\\', '/', $backup));
        $this->assertStringNotContainsString('/public/', str_replace('\\', '/', $backup));
    }

    public function test_it_copies_the_env_verbatim()
    {
        $backup = $this->backups()->create();

        $this->assertSame($this->envContents(), file_get_contents($backup));
    }

    /** Two runs in the same second must not have the second replace the first. */
    public function test_it_never_overwrites_an_existing_backup()
    {
        $store = $this->backups();

        $first = $store->create('encrypt');
        $second = $store->create('encrypt');

        $this->assertNotSame($first, $second);
        $this->assertFileExists($first);
        $this->assertFileExists($second);
    }

    /** A hurried "git add ." must not be able to commit one. */
    public function test_the_directory_denies_itself_to_git()
    {
        $store = $this->backups();
        $store->create();

        $this->assertSame("*\n", file_get_contents($store->directory() . '/.gitignore'));
    }

    public function test_restoring_puts_the_old_contents_back()
    {
        $store = $this->backups();
        $backup = $store->create('encrypt');

        $this->writeEnv("APP_ENV=local\nDB_PASSWORD=\"enc:something\"\n");
        $this->assertTrue($store->restore($backup));

        $this->assertSame('plain-text', EnvFile::value($this->envContents(), 'DB_PASSWORD'));
    }

    /** Restoring the wrong file must not be the end of the story. */
    public function test_restoring_backs_up_the_current_env_first()
    {
        $store = $this->backups();
        $backup = $store->create('encrypt');

        $this->writeEnv("APP_ENV=local\nDB_PASSWORD=\"enc:something\"\n");
        $store->restore($backup);

        $preRestore = array_filter($store->all(), function ($path) {
            return strpos(basename($path), 'pre-restore') !== false;
        });

        $this->assertCount(1, $preRestore);
        $this->assertStringContainsString('enc:something', file_get_contents(array_values($preRestore)[0]));
    }

    public function test_write_env_replaces_the_file_and_leaves_no_temporary()
    {
        $this->assertTrue($this->backups()->writeEnv("APP_ENV=local\nDB_PASSWORD=new\n"));

        $this->assertSame('new', EnvFile::value($this->envContents(), 'DB_PASSWORD'));
        $this->assertFileDoesNotExist($this->envPath() . '.envcrypt-tmp');
    }
}
