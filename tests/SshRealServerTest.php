<?php

declare(strict_types=1);

namespace Porta\SshPlugin\Tests;

use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\TestCase;
use Porta\Filesystem\RemoteEntry;
use Porta\Filesystem\RemoteFile;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteStamp;
use Porta\SshPlugin\SshTransport;
use Porta\Tests\Support\DummyServer;
use Porta\Tests\Support\ExercisesPresence;
use Porta\Tests\Support\ExercisesTransport;
use Porta\Tests\Support\PresencePeer;
use Porta\Tests\Support\TempProject;

/**
 * The transport across a real process and socket boundary: the ssh it runs
 * is the stand-in client, which hands each command to a server process
 * ({@see DummyServer::ssh()}) and exits as ssh exits — 255 when the server
 * is not there. Presence is held to it with two Porta instances on one
 * project there.
 *
 * Large: each look is several real processes, and a caret move waits out
 * the two seconds a stamp takes to settle.
 */
#[Large]
final class SshRealServerTest extends TestCase
{
    use ExercisesPresence;
    use ExercisesTransport;

    private TempProject $root;

    private DummyServer $server;

    private RemoteSpec $spec;

    protected function setUp(): void
    {
        $this->root = new TempProject(['README.md' => "# hi\n", 'src/App.php' => "<?php\n", 'src/A.php' => "<?php\n"]);
        $this->server = DummyServer::ssh($this->root->dir);
        $this->spec = new RemoteSpec('ssh', 'example.org', (string) \realpath($this->root->dir), 'ada', $this->server->port);
    }

    private function transport(): SshTransport
    {
        return new SshTransport($this->spec, \sys_get_temp_dir(), DummyServer::SSH_CLIENT);
    }

    public function testEveryVerbOverTheWire(): void
    {
        $transport = $this->transport();

        $entries = $this->answer(static fn (\Closure $done) => $transport->list('', $done));
        $this->assertIsArray($entries);
        $names = \array_map(static fn (RemoteEntry $e): string => $e->name.($e->isDir ? '/' : ''), $entries);
        \sort($names);
        $this->assertSame(['README.md', 'src/'], $names);

        $file = $this->answer(static fn (\Closure $done) => $transport->fetch('README.md', $done));
        $this->assertInstanceOf(RemoteFile::class, $file);
        $this->assertSame("# hi\n", $file->bytes);
        $this->assertInstanceOf(RemoteStamp::class, $this->answer(static fn (\Closure $done) => $transport->put('README.md', "# changed\n", $file->stamp, $done)));
        $this->assertNull($this->answer(static fn (\Closure $done) => $transport->put('README.md', 'lost', $file->stamp, $done)), 'changed since: refused');
        $this->assertSame("# changed\n", \file_get_contents($this->root->path('README.md')));

        $this->assertTheStructuralVerbs($transport, $this->root);
    }

    public function testAServerThatIsNotThereSaysWhy(): void
    {
        $this->server->stop();
        $transport = $this->transport();

        $this->assertSame('ssh: connect to host example.org port '.$this->server->port.': Connection refused', $this->answer(static fn (\Closure $done) => $transport->list('', $done)));
    }

    public function testTwoPortaInstancesOnTheServerSeeEachOther(): void
    {
        [$ada, $bo] = $this->peers();

        $this->assertTwoInstancesSeeEachOther($ada, $bo);
        $this->assertCount(2, (array) \glob($this->root->path('.porta/presence/*.json')), 'on the server, beside the project: one each');
    }

    public function testALostServerKeepsTheOthersAsLastSeen(): void
    {
        [$ada, $bo] = $this->peers();
        $this->assertTwoInstancesSeeEachOther($ada, $bo);

        $this->assertALostServerKeepsTheOthersAsLastSeen($ada, $bo, $this->server);
    }

    public function testOneWhoGoesQuietIsGone(): void
    {
        [$ada, $bo] = $this->peers();
        $this->assertTwoInstancesSeeEachOther($ada, $bo);

        $this->assertOneWhoGoesQuietIsGone($ada, $bo, 15.0);
    }

    /** @return array{PresencePeer, PresencePeer} */
    private function peers(): array
    {
        return [
            new PresencePeer('Ada', $this->spec, $this->transport(...)),
            new PresencePeer('Bo', $this->spec, $this->transport(...)),
        ];
    }
}
