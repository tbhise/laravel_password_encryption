<?php

namespace Tusharb\EnvCrypt\Tests;

use PHPUnit\Framework\TestCase;
use Tusharb\EnvCrypt\ConnectionKeys;

/**
 * Detection driven by the database configuration rather than by names.
 *
 * The distinction is the point: MAIL_PASSWORD and REDIS_PASSWORD are excluded
 * because they are not any connection's password, not because they are on a
 * list - and DB_PASSWORD_REPORTING is found for the same reason, without any
 * naming convention needing to predict it.
 */
class ConnectionKeysTest extends TestCase
{
    private $env = "APP_ENV=local\n"
        . "DB_PASSWORD=main-pass\n"
        . "DB_PASSWORD_SECOND=second-pass\n"
        . "DB_PASSWORD_REPORTING=reporting-pass\n"
        . "MAIL_PASSWORD=mail-pass\n"
        . "REDIS_PASSWORD=redis-pass\n";

    private function connections()
    {
        return [
            'mysql' => ['driver' => 'mysql', 'password' => 'main-pass'],
            'second' => ['driver' => 'mysql', 'password' => 'second-pass'],
            'reporting' => ['driver' => 'mysql', 'password' => 'reporting-pass'],
            'sqlite' => ['driver' => 'sqlite', 'password' => null],
        ];
    }

    private function keys(?array $connections = null, $env = null)
    {
        return new ConnectionKeys(
            $connections ?: $this->connections(),
            $env ?: $this->env,
            ['mysql', 'mariadb', 'pgsql', 'sqlsrv']
        );
    }

    public function test_it_finds_every_key_a_connection_actually_uses()
    {
        $this->assertSame(
            ['DB_PASSWORD', 'DB_PASSWORD_SECOND', 'DB_PASSWORD_REPORTING'],
            array_keys($this->keys()->keys())
        );
    }

    public function test_it_reports_which_connections_use_each_key()
    {
        $this->assertSame(['reporting'], $this->keys()->keys()['DB_PASSWORD_REPORTING']);
    }

    public function test_it_ignores_passwords_that_belong_to_no_connection()
    {
        $found = array_keys($this->keys()->keys());

        $this->assertNotContains('MAIL_PASSWORD', $found);
        $this->assertNotContains('REDIS_PASSWORD', $found);
    }

    /** Two connections can legitimately share one key. */
    public function test_a_shared_key_is_listed_once()
    {
        $connections = [
            'mysql' => ['driver' => 'mysql', 'password' => 'main-pass'],
            'replica' => ['driver' => 'mysql', 'password' => 'main-pass'],
        ];

        $keys = $this->keys($connections)->keys();

        $this->assertSame(['DB_PASSWORD'], array_keys($keys));
        $this->assertSame(['mysql', 'replica'], $keys['DB_PASSWORD']);
    }

    /**
     * When a database password happens to equal an unrelated one, the DB_ key
     * is the one to manage - editing MAIL_PASSWORD would not change the
     * connection.
     */
    public function test_a_db_prefixed_key_wins_an_ambiguous_match()
    {
        $env = "MAIL_PASSWORD=shared\nDB_PASSWORD=shared\n";
        $connections = ['mysql' => ['driver' => 'mysql', 'password' => 'shared']];

        $this->assertSame(['DB_PASSWORD'], array_keys($this->keys($connections, $env)->keys()));
    }

    public function test_an_empty_password_is_not_a_target()
    {
        $connections = ['mysql' => ['driver' => 'mysql', 'password' => '']];

        $this->assertSame([], $this->keys($connections)->keys());
    }

    /** A password not in .env cannot be managed by editing .env. */
    public function test_it_reports_a_password_that_is_not_in_the_env_file()
    {
        $connections = ['mysql' => ['driver' => 'mysql', 'password' => 'hardcoded-in-config']];

        $this->assertSame(['mysql'], $this->keys($connections)->unmanageable());
    }

    /**
     * The silent failure worth catching: nothing decrypts for an unwrapped
     * driver, so "enc:..." reaches PDO as the password itself.
     */
    public function test_it_reports_an_encrypted_password_on_an_unwrapped_driver()
    {
        $connections = [
            'mysql' => ['driver' => 'mysql', 'password' => 'enc:AAAA'],
            'odbc' => ['driver' => 'odbc', 'password' => 'enc:AAAA'],
        ];

        $this->assertSame(['odbc' => 'odbc'], $this->keys($connections)->encryptedOnUnwrappedDriver());
    }

    public function test_it_reports_url_configured_connections()
    {
        $connections = [
            'mysql' => ['driver' => 'mysql', 'password' => 'main-pass'],
            'byurl' => ['driver' => 'mysql', 'url' => 'mysql://user:pass@host/db'],
        ];

        $this->assertSame(['byurl'], $this->keys($connections)->urlConfigured());
    }
}
