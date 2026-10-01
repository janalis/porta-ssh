<?php

declare(strict_types=1);

namespace Porta\SshPlugin\Console;

use Porta\Config\PortaHome;
use Porta\Filesystem\RemoteSpec;
use Porta\Plugin\Api\OpenRemoteCommand;
use Porta\SshPlugin\SshTransport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `porta ssh user@host:/path` — open a project on a server over ssh.
 *
 * Connects first, on this terminal: ssh asks here for a passphrase, a
 * password or an unknown host key, and the connection it opens stays up for
 * the IDE to share. The IDE never prompts — so this is the one place those
 * questions can be answered.
 */
#[AsCommand(
    name: 'ssh',
    description: 'Open a project on a server over ssh',
)]
final class OpenCommand extends OpenRemoteCommand
{
    /**
     * @param (\Closure(string): int)|null       $launch opens the IDE on a directory and waits for it; null runs this Porta
     * @param string|null                        $home   where caches and connections are kept; null is the user's home
     * @param (\Closure(list<string>): int)|null $ssh    runs ssh on this terminal and answers its exit code; null runs it
     */
    public function __construct(
        ?\Closure $launch = null,
        private readonly ?string $home = null,
        private readonly ?\Closure $ssh = null,
    ) {
        parent::__construct($launch, $home);
        $this->setHelp(<<<'HELP'
            Opens a project that lives on a server, over ssh:

              <info>porta ssh deploy@example.org:/srv/app</info>
              <info>porta ssh ssh://deploy@example.org:2222/srv/app</info>

            ssh connects here first, so a passphrase, a password or a new host key is
            answered on this terminal; the connection then stays up for Porta to use.
            Your <info>~/.ssh/config</info> applies — a host alias works as the host.

            The project is kept under <info>~/.porta/remote/</info>: a file is fetched the
            first time you open it, and every save is sent back to the server.
            HELP);
    }

    #[\Override]
    protected function schemes(): array
    {
        return ['ssh', 'sftp'];
    }

    #[\Override]
    protected function connect(RemoteSpec $spec, InputInterface $input, OutputInterface $output): ?string
    {
        $controlDir = SshTransport::controlDir((string) ($this->home ?? PortaHome::base()));
        $code = ($this->ssh ?? self::interactive(...))(['ssh', ...SshTransport::sharing($spec, $controlDir), '--', SshTransport::target($spec), 'true']);

        return 0 === $code ? null : \sprintf('Cannot reach %s (ssh exited with %d)', $spec->host, $code);
    }

    /**
     * $command on this terminal, so ssh can ask what it needs to. The tests
     * hand the command a runner of their own: a password prompt needs a
     * person.
     *
     * @param list<string> $command
     */
    // @codeCoverageIgnoreStart
    private static function interactive(array $command): int
    {
        $pipes = [];
        $process = \proc_open($command, [\STDIN, \STDOUT, \STDERR], $pipes);

        return false === $process ? 127 : \proc_close($process);
    }

    // @codeCoverageIgnoreEnd
}
