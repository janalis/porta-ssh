<?php

declare(strict_types=1);

namespace Porta\SshPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteSpec;
use Porta\SshPlugin\Console\OpenCommand;
use Porta\SshPlugin\SshTransport;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Tester\CommandTester;

/** `porta ssh` connects on this terminal first, then opens Porta on the project's cache. */
final class OpenCommandTest extends TestCase
{
    private TempProject $home;
    /** @var list<list<string>> */
    private array $ran = [];
    private ?string $opened = null;

    protected function setUp(): void
    {
        $this->home = new TempProject();
    }

    private function command(int $sshExit = 0): CommandTester
    {
        return new CommandTester(new OpenCommand(
            function (string $root): int {
                $this->opened = $root;

                return 0;
            },
            $this->home->dir,
            function (array $command) use ($sshExit): int {
                $this->ran[] = $command;

                return $sshExit;
            },
        ));
    }

    public function testItConnectsThenOpensTheCache(): void
    {
        $tester = $this->command();

        $this->assertSame(0, $tester->execute(['location' => 'deploy@example.org:/srv/app']));

        $sharing = SshTransport::sharing(new RemoteSpec('ssh', 'example.org', '/srv/app'), $this->home->path('.porta/ssh'));
        $this->assertSame([['ssh', ...$sharing, '--', 'deploy@example.org', 'true']], $this->ran, 'interactive: no BatchMode, so ssh may ask');
        $this->assertNotNull($this->opened);
        $this->assertSame('ssh://deploy@example.org/srv/app', ProjectLocation::forRoot($this->opened)->remote?->url());
        $this->assertStringContainsString('Opening deploy@example.org:/srv/app', $tester->getDisplay());
        $this->assertFileExists($this->opened.'/.porta/onboarding.json', 'the server\'s project is set up already');
    }

    public function testAnSftpUrlIsOpenedAsSuch(): void
    {
        $this->command()->execute(['location' => 'sftp://example.org:2222/srv/app']);

        $this->assertSame('sftp', ProjectLocation::forRoot((string) $this->opened)->remote?->scheme);
        $this->assertContains('2222', $this->ran[0]);
    }

    public function testAServerItCannotReachOpensNothing(): void
    {
        $tester = $this->command(sshExit: 255);

        $this->assertSame(1, $tester->execute(['location' => 'example.org:/srv/app']));

        $this->assertNull($this->opened);
        $this->assertStringContainsString('Cannot reach example.org (ssh exited with 255)', $tester->getDisplay());
    }

    public function testWhatIsNotAnSshLocationIsRefused(): void
    {
        $tester = $this->command();

        $this->assertSame(1, $tester->execute(['location' => 'ftp://example.org/']));
        $this->assertSame(1, $tester->execute(['location' => '/a/local/path']));

        $this->assertSame([], $this->ran);
        $this->assertStringContainsString('Not a ssh/sftp location: /a/local/path', $tester->getDisplay());
    }
}
