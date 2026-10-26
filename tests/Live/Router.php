<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Live;

/**
 * The router seen through its API, independent of the library under test: the reference the SSH results are compared
 * with, and the set-up and clean-up of the tests. It is tools/chr/routeros.php (the client of the CHR provisioning)
 * behind a class that fails on a !trap. PHPStan does not know that file's class (it is loaded with require_once, not
 * by the autoloader), so the places that touch it are marked.
 */
final class Router
{
    private function __construct(private readonly object $api)
    {
    }

    /**
     * @throws \RuntimeException the connection or the login failed
     */
    public static function connect(Env $env): self
    {
        require_once __DIR__ . '/../../tools/chr/routeros.php';

        // @phpstan-ignore class.notFound
        $api = \ChrApi::connect($env->host, $env->apiPort, 10.0);
        $router = new self($api); // @phpstan-ignore argument.type
        /** @var ?string $trap */
        $trap = $router->api->login($env->user, $env->password, 10.0); // @phpstan-ignore method.notFound
        if ($trap !== null) {
            $router->close();
            throw new \RuntimeException('Login to the API of the router failed: ' . $trap);
        }

        return $router;
    }

    public function close(): void
    {
        $this->api->close(); // @phpstan-ignore method.notFound
    }

    /**
     * The rows of a command (`/ip/address/print`); `?name=value` words are the query, `=.proplist=a,b` the columns.
     *
     * @param list<string> $words
     * @return list<array<string, string>>
     * @throws \RuntimeException the router answered with a !trap
     */
    public function rows(string $command, array $words = []): array
    {
        $reply = $this->call($command, $words);
        if ($reply['trap'] !== null) {
            throw new \RuntimeException($command . ': ' . $reply['trap']);
        }

        return $reply['re'];
    }

    /**
     * Creates an item the way the router itself takes it.
     *
     * @param array<string, string> $attributes
     * @return string the `.id`
     * @throws \RuntimeException the router answered with a !trap
     */
    public function add(string $menu, array $attributes): string
    {
        $words = [];
        foreach ($attributes as $name => $value) {
            $words[] = '=' . $name . '=' . $value;
        }
        $reply = $this->call($menu . '/add', $words);
        if ($reply['trap'] !== null) {
            throw new \RuntimeException($menu . '/add: ' . $reply['trap']);
        }

        return $reply['done']['ret'] ?? throw new \RuntimeException($menu . '/add returned no id');
    }

    /**
     * Removes every item of the menu for which `?key=value` holds.
     *
     * @return int how many were removed
     */
    public function removeWhere(string $menu, string $key, string $value): int
    {
        $removed = 0;
        foreach ($this->rows($menu . '/print', ['?' . $key . '=' . $value, '=.proplist=.id']) as $row) {
            $this->rows($menu . '/remove', ['=.id=' . $row['.id']]);
            $removed++;
        }

        return $removed;
    }

    /**
     * @param list<string> $words
     * @return array{done: array<string, string>, re: list<array<string, string>>, trap: ?string}
     */
    private function call(string $command, array $words): array
    {
        /** @var array{done: array<string, string>, re: list<array<string, string>>, trap: ?string} $reply */
        $reply = $this->api->call($command, $words); // @phpstan-ignore method.notFound

        return $reply;
    }
}
