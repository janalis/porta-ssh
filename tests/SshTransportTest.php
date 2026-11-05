<?php

declare(strict_types=1);

namespace Porta\SshPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\RemoteEntry;
use Porta\Filesystem\RemoteFile;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteStamp;
use Porta\SshPlugin\SshTransport;
use Porta\Tests\Support\MeetsTheStructuralVerbs;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;

/**
 * The scripts the server runs, run for real — by `sh`, with a fake ssh that
 * drops the destination — so the quoting, the stamps and the conflict check
 * are the ones a server would see.
 */
final class SshTransportTest extends TestCase
{
    use MeetsTheStructuralVerbs;
    use PreservesPath;

    private const string FAKE_SSH = __DIR__.'/fixtures/fake-ssh';

    private TempProject $server;
    private SshTransport $transport;

    protected function setUp(): void
    {
        $this->server = new TempProject(['README.md' => "# hi\n", 'src/App.php' => "<?php\n", "it's a \"name\" with spaces.txt" => 'quoted']);
        $this->transport = new SshTransport(new RemoteSpec('ssh', 'example.org', $this->server->dir), '/tmp', self::FAKE_SSH);
    }

    private function transportUnderTest(): SshTransport
    {
        return $this->transport;
    }

    private function serverUnderTest(): TempProject
    {
        return $this->server;
    }

    public function testItListsADirectory(): void
    {
        $entries = $this->answer(fn (\Closure $done) => $this->transport->list('', $done));

        $this->assertIsArray($entries);
        $byName = [];
        foreach ($entries as $entry) {
            $this->assertInstanceOf(RemoteEntry::class, $entry);
            $byName[$entry->name] = $entry;
        }
        \ksort($byName);
        $this->assertSame(['README.md', 'it\'s a "name" with spaces.txt', 'src'], \array_keys($byName));
        $this->assertTrue($byName['src']->isDir);
        $this->assertFalse($byName['README.md']->isDir);
        $this->assertEquals($this->stampOf($this->server, 'README.md'), $byName['README.md']->stamp);
    }

    public function testListingAMissingDirectorySaysWhy(): void
    {
        $answer = $this->answer(fn (\Closure $done) => $this->transport->list('nope', $done));

        $this->assertIsString($answer);
        $this->assertStringContainsString('nope', $answer);
    }

    public function testItFetchesAFileWithItsStamp(): void
    {
        $file = $this->answer(fn (\Closure $done) => $this->transport->fetch('it\'s a "name" with spaces.txt', $done));

        $this->assertInstanceOf(RemoteFile::class, $file);
        $this->assertSame('quoted', $file->bytes);
        $this->assertEquals($this->stampOf($this->server, 'it\'s a "name" with spaces.txt'), $file->stamp);
    }

    public function testAFileWhoseContentStartsLikeAStampIsStillItsContent(): void
    {
        $this->server->write('odd.txt', "12 34\nmore\n");

        $file = $this->answer(fn (\Closure $done) => $this->transport->fetch('odd.txt', $done));

        $this->assertInstanceOf(RemoteFile::class, $file);
        $this->assertSame("12 34\nmore\n", $file->bytes);
    }

    public function testItWalksTheWholeTreeSkippingWhatItIsTold(): void
    {
        $this->server->write('vendor/x/Skipped.php', '');
        $this->server->write('deep/er/Found.php', '<?php');
        $this->server->write('lib/vendor/Nested.php', '');

        $entries = $this->answer(fn (\Closure $done): bool => $this->transport->walk('', ['vendor', "it's"], $done));

        $this->assertIsArray($entries);
        $byPath = [];
        foreach ($entries as $entry) {
            $this->assertInstanceOf(RemoteEntry::class, $entry);
            $byPath[$entry->name] = $entry;
        }
        \ksort($byPath);
        $this->assertSame(
            ['README.md', 'deep', 'deep/er', 'deep/er/Found.php', 'it\'s a "name" with spaces.txt', 'lib', 'src', 'src/App.php'],
            \array_keys($byPath),
            'a skipped name is skipped at any depth — as a directory only',
        );
        $this->assertTrue($byPath['deep/er']->isDir);
        $this->assertEquals($this->stampOf($this->server, 'deep/er/Found.php'), $byPath['deep/er/Found.php']->stamp);
    }

    public function testAWalkWithNothingToSkipAndOneThatFails(): void
    {
        $this->assertCount(4, (array) $this->answer(fn (\Closure $done): bool => $this->transport->walk('', [], $done)));
        $this->assertIsString($this->answer(fn (\Closure $done): bool => $this->transport->walk('nope', [], $done)));
    }

    /**
     * One folder nobody may read leaves the rest of the walk standing. GNU find
     * (a Linux server's) prints the rest and exits 1 over it; this Mac's BSD
     * find takes the script's other branch, so a find that answers as GNU's
     * does stands in for it.
     */
    public function testAWalkPassesOverAFolderItMayNotRead(): void
    {
        $bin = new TempProject();
        $bin->write(
            'find',
            "#!/bin/sh\ncase \"$*\" in *'-maxdepth 0'*) exit 0;; esac\nprintf 'f\\t5\\t1700000000.0\\tREADME.md\\0d\\t0\\t1700000000.0\\tsrc\\0'\necho \"find: './mysql-data': Permission denied\" >&2\nexit 1\n",
        );
        \chmod($bin->path('find'), permissions: 0o755);
        \putenv('PATH='.$bin->dir.':'.(string) \getenv('PATH'));

        $entries = $this->answer(fn (\Closure $done): bool => $this->transport->walk('', [], $done));

        $this->assertIsArray($entries, 'not refused');
        $this->assertCount(2, $entries, 'everything it printed is kept');
    }

    public function testACommandRunsInTheProjectsDirectoryOnTheServer(): void
    {
        $this->server->write('sub/x.txt', "it's here");

        $command = $this->transport->remoteCommand(['grep', '-rlF', '--', "it's", 'sub']);

        $this->assertSame([self::FAKE_SSH, '-o', 'BatchMode=yes'], \array_slice($command, offset: 0, length: 3));
        $this->assertSame("sub/x.txt\n", \shell_exec(\implode(' ', \array_map(\escapeshellarg(...), $command))));
    }

    public function testFetchingAMissingFileSaysWhy(): void
    {
        $this->assertIsString($this->answer(fn (\Closure $done) => $this->transport->fetch('missing.txt', $done)));
    }

    public function testItWritesAFileAndAnswersItsNewStamp(): void
    {
        $stamp = $this->answer(fn (\Closure $done) => $this->transport->put('new dir/new.txt', "fresh\n", null, $done));

        $this->assertSame("fresh\n", \file_get_contents($this->server->path('new dir/new.txt')), 'the directory it needs is made');
        $this->assertEquals($this->stampOf($this->server, 'new dir/new.txt'), $stamp);
        $this->assertSame([], \glob($this->server->path('new dir/.porta.*')), 'no temporary left behind');
    }

    public function testAWriteOverTheStampItWasFetchedAtReplacesTheFileAndKeepsItsMode(): void
    {
        \chmod($this->server->path('README.md'), permissions: 0o640);

        $stamp = $this->answer(fn (\Closure $done) => $this->transport->put('README.md', 'mine', $this->stampOf($this->server, 'README.md'), $done));

        $this->assertInstanceOf(RemoteStamp::class, $stamp);
        $this->assertSame('mine', \file_get_contents($this->server->path('README.md')));
        \clearstatcache();
        $this->assertSame(0o640, \fileperms($this->server->path('README.md')) & 0o777);
    }

    public function testANewFileGetsTheModeTheServersUmaskGives(): void
    {
        $this->answer(fn (\Closure $done) => $this->transport->put('page.html', '<p>', null, $done));

        \clearstatcache();
        $umask = \umask();
        $this->assertSame(0o666 & ~$umask, \fileperms($this->server->path('page.html')) & 0o777, 'not mktemp\'s 0600');
    }

    public function testAWriteToALinkGoesThroughItAndKeepsTheLink(): void
    {
        $this->server->write('shared/.env', "A=1\n");
        \chmod($this->server->path('shared/.env'), permissions: 0o600);
        \symlink('shared/.env', $this->server->path('.env'));

        $fetched = $this->answer(fn (\Closure $done) => $this->transport->fetch('.env', $done));
        $this->assertInstanceOf(RemoteFile::class, $fetched);
        $this->assertSame("A=1\n", $fetched->bytes);

        $stamp = $this->answer(fn (\Closure $done) => $this->transport->put('.env', "A=2\n", $fetched->stamp, $done));

        $this->assertInstanceOf(RemoteStamp::class, $stamp);
        $this->assertTrue(\is_link($this->server->path('.env')), 'still the link');
        $this->assertSame("A=2\n", \file_get_contents($this->server->path('shared/.env')), 'its target written');
        \clearstatcache();
        $this->assertSame(0o600, \fileperms($this->server->path('shared/.env')) & 0o777);
        $this->assertSame([], \glob($this->server->path('.porta.*')), 'no temporary left behind');

        // Someone else edits the target: a save made from what was had is refused.
        \file_put_contents($this->server->path('shared/.env'), data: "A=3 # theirs\n");
        $this->assertNull($this->answer(fn (\Closure $done) => $this->transport->put('.env', "A=4\n", $stamp, $done)), "the target's stamp moved, not the link's");
        $this->assertSame("A=3 # theirs\n", \file_get_contents($this->server->path('shared/.env')), 'their edit kept');
    }

    public function testAWriteOverAChangedFileIsRefusedAndWritesNothing(): void
    {
        $stale = new RemoteStamp(1, 1);

        $this->assertNull($this->answer(fn (\Closure $done) => $this->transport->put('README.md', 'mine', $stale, $done)));
        $this->assertSame("# hi\n", \file_get_contents($this->server->path('README.md')));
        $this->assertNull($this->answer(fn (\Closure $done) => $this->transport->put('gone.txt', 'mine', $stale, $done)), 'nor over a file deleted since');
        $this->assertFileDoesNotExist($this->server->path('gone.txt'));
    }

    public function testAWriteThatCannotHappenSaysWhy(): void
    {
        $this->server->write('file', 'x');

        $this->assertIsString($this->answer(fn (\Closure $done) => $this->transport->put('file/under.txt', 'x', null, $done)));
    }

    public function testAFailedRenameSaysWhy(): void
    {
        $this->assertIsString($this->answer(fn (\Closure $done) => $this->transport->rename('missing', 'there', $done)));
    }

    public function testAnSshThatCannotRunSaysSo(): void
    {
        $transport = new SshTransport(new RemoteSpec('ssh', 'h', '/'), '/tmp', '/nonexistent/ssh');

        $this->assertSame('/nonexistent/ssh: command not found', $this->answer(static fn (\Closure $done) => $transport->mkdir('x', $done)));
    }

    public function testTheCommandLineQuotesEveryWordAndSharesTheConnection(): void
    {
        $transport = new SshTransport(new RemoteSpec('ssh', 'example.org', '/srv', 'deploy', 2222), '/home/me/.porta/ssh');

        $this->assertSame(
            [
                'ssh',
                '-o',
                'BatchMode=yes',
                '-o',
                'ConnectTimeout=10',
                '-o',
                'ServerAliveInterval=15',
                '-o',
                'ServerAliveCountMax=3',
                '-o',
                'ControlMaster=auto',
                '-o',
                'ControlPath=/home/me/.porta/ssh/%C',
                '-o',
                'ControlPersist=600',
                '-p',
                '2222',
                '--',
                'deploy@example.org',
                "sh -c 'echo \"\$1\"' sh 'it'\\''s' ''",
            ],
            $transport->command('echo "$1"', ["it's", '']),
        );
    }

    public function testADirectoryTooDeepForASocketSharesNoConnection(): void
    {
        $deep = '/'.\str_repeat('d', times: 61);
        $this->assertContains('ControlMaster=auto', SshTransport::sharing(new RemoteSpec('ssh', 'h', '/'), $deep), 'just short enough');
        $this->assertSame([], SshTransport::sharing(new RemoteSpec('ssh', 'h', '/'), $deep.'d'));
        $this->assertSame('h', SshTransport::target(new RemoteSpec('ssh', 'h', '/')));
    }

    public function testQuotingSurvivesTheShell(): void
    {
        foreach (["it's", '$HOME', '`id`', 'a b', "new\nline", '', '\\'] as $word) {
            $this->assertSame($word, \shell_exec('printf %s '.SshTransport::quote($word)) ?? '', $word.' reaches the program as it is');
        }
    }

    public function testClosingCancelsWhatIsInFlight(): void
    {
        $answered = false;
        $this->transport->list('', static function () use (&$answered): void {
            $answered = true;
        });

        $this->transport->close();
        $this->pump(0.2);

        $this->assertFalse($answered);
    }

    public function testTheControlDirectoryIsMadePrivate(): void
    {
        $home = new TempProject();

        $dir = SshTransport::controlDir($home->dir);

        $this->assertSame($home->path('.porta/ssh'), $dir);
        $this->assertSame(0o700, \fileperms($dir) & 0o777);
        $this->assertSame($dir, SshTransport::controlDir($home->dir));
    }

    public function testAControlDirectoryThatCannotBeMadeSharesNoConnection(): void
    {
        // A file holds the name the folder needs: ssh would exit, not connect, over a missing one.
        $home = new TempProject(['.porta' => 'not a folder']);

        $dir = SshTransport::controlDir($home->dir);

        $this->assertNull($dir);
        $this->assertSame([], SshTransport::sharing(new RemoteSpec('ssh', 'h', '/'), $dir));
        $this->assertSame(['-p', '2222'], SshTransport::sharing(new RemoteSpec('ssh', 'h', '/', port: 2222), $dir), 'the port still goes');
    }

    public function testAnUploadCutShortNeverReplacesTheFile(): void
    {
        $put = Probe::getStatic(SshTransport::class, 'PUT');
        $this->assertIsString($put);
        $command = $this->transport->command($put, [$this->server->path('README.md'), '', '1000']);
        $pipes = [];
        $process = \proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        \fwrite($pipes[0], data: 'only the first part');
        \fclose($pipes[0]); // the connection cut: stdin ends early
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        $this->assertSame(4, \proc_close($process));
        $this->assertSame("the upload was cut short\n", $stderr);
        $this->assertSame("# hi\n", \file_get_contents($this->server->path('README.md')), 'the whole file stays');
        $this->assertSame([], \glob($this->server->path('.porta.*')), 'and no temporary is left');
    }

    public function testATemporaryLeftBehindIsNeverListed(): void
    {
        $this->server->write('.porta.Ab12Cd', 'half');
        $this->server->write('src/.porta.Zz99zZ', 'half');

        $listed = $this->answer(fn (\Closure $done) => $this->transport->list('', $done));
        $walked = $this->answer(fn (\Closure $done): bool => $this->transport->walk('', [], $done));

        $this->assertIsArray($listed);
        $this->assertIsArray($walked);
        $names = static fn (array $entries): array => \array_map(static fn (RemoteEntry $e): string => $e->name, $entries);
        $this->assertNotContains('.porta.Ab12Cd', $names($listed));
        $this->assertNotContains('src/.porta.Zz99zZ', $names($walked));
        $this->assertContains('README.md', $names($listed));
    }

    public function testALoginRefusalIsKnownForOne(): void
    {
        $this->assertTrue($this->transport->refusesLogin('deploy@example.org: Permission denied (publickey,password).'));
        $this->assertFalse($this->transport->refusesLogin('ssh: connect to host example.org port 22: Connection refused'));
        $this->assertFalse($this->transport->refusesLogin('mkdir: /srv/x: Permission denied'), 'the server refusing a file is not refusing us');
    }
}
