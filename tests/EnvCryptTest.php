<?php

namespace Tusharb\EnvCrypt\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tusharb\EnvCrypt\EnvCrypt;

class EnvCryptTest extends TestCase
{
    /** @var string */
    private $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/envcrypt-tests-' . getmypid();

        if (! is_dir($this->root)) {
            mkdir($this->root, 0777, true);
        }

        $this->writeEnv("APP_ENV=local\nENVCRYPT_KEY_VAR=TESTS_BUILD_TAG\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/.env');
        @rmdir($this->root);

        EnvCrypt::reset();

        parent::tearDown();
    }

    private function writeEnv($contents)
    {
        file_put_contents($this->root . '/.env', $contents);

        EnvCrypt::reset();
        EnvCrypt::configure(['base_path' => $this->root]);
    }

    public function test_it_reads_the_secret_name_from_the_env_file()
    {
        $this->assertSame('TESTS_BUILD_TAG', EnvCrypt::rootKeyVar());
    }

    public function test_it_round_trips_a_value()
    {
        $key = EnvCrypt::generateRootKey();

        $this->assertSame('S#$a%n12d', EnvCrypt::decrypt(EnvCrypt::encrypt('S#$a%n12d', $key), $key));
    }

    public function test_the_same_plaintext_never_encrypts_alike()
    {
        $key = EnvCrypt::generateRootKey();

        $this->assertNotSame(EnvCrypt::encrypt('x', $key), EnvCrypt::encrypt('x', $key));
    }

    public function test_it_rejects_a_value_encrypted_under_another_secret()
    {
        $payload = EnvCrypt::encrypt('x', EnvCrypt::generateRootKey());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/signature check failed/');

        EnvCrypt::decrypt($payload, EnvCrypt::generateRootKey());
    }

    public function test_it_rejects_a_tampered_ciphertext()
    {
        $key = EnvCrypt::generateRootKey();
        $raw = base64_decode(substr(EnvCrypt::encrypt('x', $key), 4), true);

        // Flip a byte inside the ciphertext, past the IV and the MAC.
        $raw[60] = $raw[60] === 'A' ? 'B' : 'A';

        $this->expectException(RuntimeException::class);

        EnvCrypt::decrypt('enc:' . base64_encode($raw), $key);
    }

    public function test_it_rejects_a_malformed_payload()
    {
        $this->expectException(RuntimeException::class);

        EnvCrypt::decrypt('enc:' . base64_encode('too short'), EnvCrypt::generateRootKey());
    }

    /** Rollback has to stay a one-line .env edit. */
    public function test_maybe_decrypt_passes_plaintext_and_non_strings_through()
    {
        $this->assertSame('plain', EnvCrypt::maybeDecrypt('plain'));
        $this->assertNull(EnvCrypt::maybeDecrypt(null));
        $this->assertFalse(EnvCrypt::maybeDecrypt(false));
    }

    public function test_the_env_file_fallback_works_in_development()
    {
        $key = EnvCrypt::generateRootKey();
        $payload = EnvCrypt::encrypt('secret-password', $key);

        $this->writeEnv(
            "APP_ENV=local\nENVCRYPT_KEY_VAR=TESTS_BUILD_TAG\nTESTS_BUILD_TAG={$key}\n"
        );

        $this->assertSame('secret-password', EnvCrypt::maybeDecrypt($payload));
    }

    /** Fails closed: an unrecognised APP_ENV counts as production. */
    public function test_the_env_file_fallback_is_refused_outside_development()
    {
        $key = EnvCrypt::generateRootKey();
        $payload = EnvCrypt::encrypt('secret-password', $key);

        $this->writeEnv(
            "APP_ENV=production\nENVCRYPT_KEY_VAR=TESTS_BUILD_TAG\nTESTS_BUILD_TAG={$key}\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not available to this process/');

        EnvCrypt::maybeDecrypt($payload);
    }

    /** Changing the context must not silently produce a different plaintext. */
    public function test_a_different_context_cannot_read_the_value()
    {
        $key = EnvCrypt::generateRootKey();
        $payload = EnvCrypt::encrypt('x', $key);

        EnvCrypt::configure(['context' => 'something-else', 'base_path' => $this->root]);

        $this->expectException(RuntimeException::class);

        EnvCrypt::decrypt($payload, $key);
    }
}
