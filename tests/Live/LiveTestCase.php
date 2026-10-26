<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Live;

use Lossik\DeviceCommunication\Client;
use Lossik\Mikrotik\Ssh\Connector;
use Lossik\Mikrotik\Ssh\FileTransfer;
use Lossik\Mikrotik\Ssh\SshOptions;
use Lossik\Mikrotik\Ssh\SshTransport;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\TestCase;

/**
 * Base of the live tests (group `live`, see tools/chr): connects to the router named by the environment and removes
 * after every test what the test left behind - each item with the comment {@see self::TAG} in the menus the tests
 * use, every address-list item of {@see self::LIST}, and the files whose name starts with {@see self::FILE_PREFIX}.
 */
abstract class LiveTestCase extends TestCase
{
    /** The comment of everything the live tests create on the router. */
    protected const TAG = 'lossik-live-test';

    /**
     * The address list of the items whose comment is the value under test: they cannot carry the tag, so they are
     * found by the list (also the 300 rows of PrintManyRowsLiveTest).
     */
    protected const LIST = 'lossik-live-list';

    /** The name prefix of the files the tests upload. */
    protected const FILE_PREFIX = 'lossik-live-';

    /** The menus in which the tests create items. */
    private const TAGGED_MENUS = [
        '/ip/firewall/address-list',
        '/ipv6/firewall/address-list',
        '/ip/firewall/nat',
        '/ip/firewall/filter',
        '/ip/firewall/mangle',
        '/queue/tree',
        '/queue/simple',
        '/ip/arp',
        '/ip/address',
        '/ppp/secret',
        '/ip/dhcp-server/lease',
        '/ipv6/dhcp-server/binding',
        '/ipv6/pool',
    ];

    /**
     * The admin sessions are shared by all tests of the run: the login of the emulated router takes 1 to 3 s (key
     * exchange), more than the commands of a test together, and the 10 s of a medium test are not much. A session
     * has no state a test could leave behind (one channel per command), and one that got closed is opened again.
     */
    private static ?SshTransport $adminTransport = null;

    private static ?FileTransfer $adminFiles = null;

    protected Env $env;

    /** @var list<SshTransport|FileTransfer> */
    private array $connections = [];

    /** The session of {@see self::execRaw()}. */
    private ?SSH2 $rawSession = null;

    protected function setUp(): void
    {
        $this->env = Env::load();
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->rawSession?->disconnect();
        $this->rawSession = null;
        // without the environment (setUp failed) there is no router to clean
        if (isset($this->env)) {
            $this->removeWhatTheTestLeft();
        }
    }

    /**
     * A console session: the shared one of the admin of the router, or (another user, other options) a new one that
     * is closed in tearDown.
     */
    protected function transport(
        ?string $user = null,
        ?string $password = null,
        ?SshOptions $options = null,
    ): SshTransport {
        if ($user === null && $password === null && $options === null) {
            if (self::$adminTransport === null || !self::$adminTransport->isOpen()) {
                self::$adminTransport = $this->env->connect();
            }

            return self::$adminTransport;
        }
        $transport = $this->env->connect($user, $password, $options);
        $this->connections[] = $transport;

        return $transport;
    }

    protected function client(): Client
    {
        return new Client($this->transport());
    }

    /**
     * An SFTP session: the shared one of the admin, or (another user) a new one that is closed in tearDown.
     */
    protected function files(?string $user = null, ?string $password = null): FileTransfer
    {
        if ($user === null && $password === null) {
            if (self::$adminFiles === null || !self::$adminFiles->isOpen()) {
                self::$adminFiles = $this->env->connectFiles();
            }

            return self::$adminFiles;
        }
        $files = $this->env->connectFiles($user, $password);
        $this->connections[] = $files;

        return $files;
    }

    protected function router(): Router
    {
        return Router::connect($this->env);
    }

    /**
     * Runs one raw console line (bulk fixture setup and cleanup, OL9) on an admin SSH session of its own, opened on
     * the first call and closed in tearDown, and fails the test with the console output when the line timed out,
     * printed anything (the lines it runs print nothing when they succeed) or ended with an exit status other than 0
     * (a refused line: 1, `fd-ssh-probe-7.16.2.txt`). Not the shared session: a line that outlasts its timeout
     * leaves the channel open, and the next command of that session would fail with "Please close the channel".
     */
    protected function execRaw(string $line): void
    {
        // 300 s: the fixture is not under test, and the 300 adds of PrintManyRowsLiveTest passed 60 s on 7.25rc3
        $this->rawSession ??= Connector::open(
            SSH2::class,
            $this->env->host,
            $this->env->user,
            $this->env->password,
            $this->env->options(300.0),
        );
        $output = $this->rawSession->exec($line);
        $timedOut = $this->rawSession->isTimeout();
        $status = $this->rawSession->getExitStatus();
        if ($timedOut || !is_string($output) || trim($output) !== '' || ($status !== false && $status !== 0)) {
            self::fail(sprintf(
                "The console refused %s (%s, exit status %s):\n%s",
                $line,
                $timedOut ? 'timed out' : 'finished',
                var_export($status, true),
                var_export($output, true),
            ));
        }
    }

    private function removeWhatTheTestLeft(): void
    {
        $router = Router::connect($this->env);
        try {
            foreach (self::TAGGED_MENUS as $menu) {
                $router->removeWhere($menu, 'comment', self::TAG);
            }
            $router->removeWhere('/ip/firewall/address-list', 'list', self::LIST);
            foreach ($router->rows('/file/print', ['=.proplist=.id,name']) as $file) {
                if (str_starts_with($file['name'] ?? '', self::FILE_PREFIX)) {
                    $router->rows('/file/remove', ['=.id=' . $file['.id']]);
                }
            }
        } finally {
            $router->close();
        }
    }
}
