<?php

declare(strict_types=1);

namespace Porta\SshPlugin\Tests;

use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\TestCase;
use Porta\Filesystem\RemoteSpec;
use Porta\SshPlugin\SshTransport;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\DummyServer;
use Porta\Tests\Support\PresencePeer;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\ShowsPresenceInTheIde;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;

/**
 * The whole way, over ssh: the IDE opens a project on a server through the
 * bundled ssh plugin — its `binary` setting pointed at the stand-in client,
 * which reaches a server process over a socket — and shows who else is at
 * work there, and what a server that goes away does to them.
 *
 * Large: every look is a handful of real processes on each side.
 */
#[Large]
final class SshPresenceE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPortaHome;
    use ShowsPresenceInTheIde;
    use UsesBundledPlugins;

    public function testTheIdeShowsWhoElseIsOnTheServer(): void
    {
        $root = new TempProject(['src/A.php' => "<?php\n\$a = 1;\n\$b = 2;\n"]);
        $server = DummyServer::ssh($root->dir);
        $spec = new RemoteSpec('ssh', 'example.org', (string) \realpath($root->dir), 'ada', $server->port);
        $ada = new PresencePeer('Ada', $spec, static fn (): SshTransport => new SshTransport($spec, \sys_get_temp_dir(), DummyServer::SSH_CLIENT));

        $this->assertTheIdeShowsWhoElseIsThere($server, $spec->url(), ['enabledPlugins' => ['ssh'], 'plugins' => ['ssh' => ['binary' => DummyServer::SSH_CLIENT]]], $ada);
    }
}
