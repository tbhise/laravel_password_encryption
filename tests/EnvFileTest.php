<?php

namespace Tusharb\EnvCrypt\Tests;

use PHPUnit\Framework\TestCase;
use Tusharb\EnvCrypt\EnvFile;
use Tusharb\EnvCrypt\TargetKeys;

class EnvFileTest extends TestCase
{
    private $env = "APP_ENV=local\n"
        . "DB_CONNECTION=mysql\n"
        . "DB_HOST=127.0.0.1\n"
        . "DB_PASSWORD='S#\$a%n12d'\n"
        . "DB_HOST_SECOND=10.0.0.2\n"
        . "DB_PASSWORD_SECOND=second-pass\n"
        . "MAIL_PASSWORD=not-a-database\n";

    /** A password containing # or $ has to be single-quoted in .env. */
    public function test_it_strips_one_layer_of_quotes()
    {
        $this->assertSame('S#$a%n12d', EnvFile::value($this->env, 'DB_PASSWORD'));
        $this->assertSame('a"b', EnvFile::unquote('\'a"b\''));
        $this->assertSame('"unbalanced', EnvFile::unquote('"unbalanced'));
    }

    public function test_it_tolerates_a_byte_order_mark()
    {
        $this->assertSame('local', EnvFile::value("\xEF\xBB\xBFAPP_ENV=local\n", 'APP_ENV'));
    }

    public function test_the_first_occurrence_wins_as_dotenv_does()
    {
        $this->assertSame('one', EnvFile::value("K=one\nK=two\n", 'K'));
    }

    public function test_it_returns_null_for_an_absent_key()
    {
        $this->assertNull(EnvFile::value($this->env, 'NOPE'));
    }

    public function test_it_replaces_a_value_in_place()
    {
        $updated = EnvFile::withValueSet($this->env, 'DB_PASSWORD', 'enc:abc', $count);

        $this->assertSame(1, $count);
        $this->assertSame('enc:abc', EnvFile::value($updated, 'DB_PASSWORD'));
        $this->assertSame('not-a-database', EnvFile::value($updated, 'MAIL_PASSWORD'));
    }

    public function test_it_appends_an_absent_key()
    {
        $updated = EnvFile::withValueSet($this->env, 'NEW_KEY', 'v', $count);

        $this->assertSame(1, $count);
        $this->assertSame('v', EnvFile::value($updated, 'NEW_KEY'));
    }

    /**
     * The suffix test is what separates a second database connection from an
     * unrelated password that happens to contain the word.
     */
    public function test_it_classifies_only_real_database_passwords()
    {
        list($database, $other) = TargetKeys::classify($this->env);

        $this->assertSame(['DB_PASSWORD', 'DB_PASSWORD_SECOND'], $database);
        $this->assertSame(['MAIL_PASSWORD'], $other);
    }

    public function test_a_declared_list_is_authoritative()
    {
        $env = "ENVCRYPT_TARGET_KEYS=\"DB_PASSWORD,DB_PASSWORD_SECOND\"\n" . $this->env;

        $this->assertSame(
            ['DB_PASSWORD', 'DB_PASSWORD_SECOND'],
            array_keys(TargetKeys::resolve($env))
        );
    }

    public function test_it_falls_back_to_db_password_when_nothing_is_declared()
    {
        $this->assertSame(['DB_PASSWORD'], array_keys(TargetKeys::resolve($this->env)));
    }

    /** A value encrypted by hand must still be found and guarded. */
    public function test_it_sweeps_up_values_already_encrypted_by_hand()
    {
        $env = str_replace('second-pass', 'enc:AAAA', $this->env);

        $this->assertSame(['DB_PASSWORD_SECOND'], array_keys(TargetKeys::resolve($env)));
    }
}
