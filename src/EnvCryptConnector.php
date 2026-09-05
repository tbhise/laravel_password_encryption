<?php

namespace Tusharb\EnvCrypt;

use Illuminate\Database\Connectors\ConnectorInterface;

/**
 * Decrypts the password as PDO is created.
 *
 * A connector is the right seam because ConnectionFactory::make() splits
 * read/write configurations ABOVE the connector, so both halves pass through
 * here. A DB::extend hook at manager level would miss the replica password.
 *
 * Decrypting at connection time - rather than in config/database.php - is also
 * what keeps the plaintext out of bootstrap/cache/config.php.
 */
final class EnvCryptConnector implements ConnectorInterface
{
    /** @var \Illuminate\Database\Connectors\ConnectorInterface */
    private $inner;

    public function __construct(ConnectorInterface $inner)
    {
        $this->inner = $inner;
    }

    public function connect(array $config)
    {
        $config['password'] = EnvCrypt::maybeDecrypt(
            isset($config['password']) ? $config['password'] : ''
        );

        return $this->inner->connect($config);
    }

    /** The connector this one wraps - useful in tests and diagnostics. */
    public function inner()
    {
        return $this->inner;
    }
}
