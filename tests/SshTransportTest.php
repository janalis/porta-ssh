<?php

declare(strict_types=1);

namespace Porta\SshPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\RemoteEntry;
use Porta\Filesystem\RemoteFile;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteStamp;
use Porta\SshPlugin\SshTransport;
use Porta\Tests\Support\ExercisesTransport;
use Porta\Tests\Support\TempProject;

/**
 * The scripts the server runs, run for real — by `sh`, with a fake ssh that
 * drops the destination — so the quoting, the stamps and the conflict check
 * are the ones a server would see.
 */
final class SshTransportTest extends TestCase
{
    use ExercisesTransport;

    private const string FAKE_SSH = __DIR__.'/fixtures/fake-ssh';

    private TempProject $server;
    private SshTransport $transport;

    protected function setUp(): void
    {
        $this->server = new TempProject(['README.md' => "# hi\n", 'src/App.php' => "<?php\n", "it's a \"name\" with spaces.txt" => 'quoted']);
        $this->transport = new SshTransport(new RemoteSpec('ssh', 'example.org', $this->server->dir), '/tmp', self::FAKE_SSH);
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

    public function testDirectoriesAreMadeMovedAndRemoved(): void
    {
        $this->assertTheStructuralVerbs($this->transport, $this->server);
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
}
