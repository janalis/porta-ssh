<?php

declare(strict_types=1);

namespace Porta\SshPlugin;

use Porta\Filesystem\CommandTransport;
use Porta\Filesystem\RemoteEntry;
use Porta\Filesystem\RemoteFile;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteStamp;

/**
 * A server's project over ssh: each verb is one `ssh` running a short POSIX
 * shell script on the server, so the server needs nothing installed — only
 * `sh`, `find` or `stat`, `cat`, `mv`, `mkdir`, `rm` and `mktemp`.
 *
 * Every call shares one connection: ssh's ControlMaster keeps it open for ten
 * minutes after the last use, so a verb costs a round trip, not a handshake.
 * BatchMode means a password or a host key nobody accepted yet is a failure,
 * not a prompt — the IDE owns the terminal. `porta ssh` is where a person
 * answers those, once, before the IDE starts.
 *
 * The server's login shell parses the command line ssh sends, so every word
 * of it is single-quoted, the one quoting every shell agrees on. The script
 * reads its paths as `$1`, `$2`: a file name is never part of the script.
 */
final class SshTransport extends CommandTransport
{
    /**
     * Prints a stamp — `size mtime` — for "$1": GNU and busybox `stat -c`,
     * else BSD `stat -f`.
     */
    private const string STAT = 'stamp() { stat -c \'%s %Y\' -- "$1" 2>/dev/null || stat -f \'%z %m\' -- "$1"; }';

    /** The entries of "$1": `type<TAB>size<TAB>mtime<TAB>name<NUL>` each, by GNU find when there is one. */
    private const string LIST = self::STAT.'
cd -- "$1" || exit 2
if find . -maxdepth 0 -printf "" >/dev/null 2>&1; then
  exec find . -mindepth 1 -maxdepth 1 -printf "%Y\t%s\t%T@\t%f\0"
fi
for f in .[!.]* ..?* *; do
  [ -e "$f" ] || continue
  if [ -d "$f" ]; then t=d; else t=f; fi
  s=$(stamp "$f") || continue
  printf "%s\t%s\t%s\t%s\0" "$t" "${s% *}" "${s#* }" "$f"
done';

    /** The stamp of "$1" on a line, then its content. */
    private const string FETCH = self::STAT.'
s=$(stamp "$1") || exit 2
printf "%s\n" "$s"
exec cat -- "$1"';

    /**
     * Stdin into "$1", through a temporary beside it so a reader never sees
     * half a file, keeping its mode — unless "$2" is a stamp the file no
     * longer has, which exits 3 and writes nothing. Prints the new stamp.
     *
     * Only when all "$3" bytes came: a connection cut mid-upload ends stdin
     * as if the file were whole, and `cat` succeeds — the count is what
     * keeps half a file from being moved over the whole one. Exits 4.
     */
    private const string PUT = self::STAT.'
d=$(dirname -- "$1")
mkdir -p -- "$d" || exit 2
if [ -n "$2" ] && [ "$(stamp "$1" 2>/dev/null)" != "$2" ]; then cat >/dev/null; exit 3; fi
t=$(mktemp "$d/.porta.XXXXXX") || exit 2
cat > "$t" || { rm -f -- "$t"; exit 2; }
[ "$(wc -c < "$t" | tr -d " ")" = "$3" ] || { rm -f -- "$t"; echo "the upload was cut short" >&2; exit 4; }
if [ -e "$1" ]; then chmod "$(stat -c %a -- "$1" 2>/dev/null || stat -f %Lp -- "$1")" "$t" 2>/dev/null; fi
mv -f -- "$t" "$1" || { rm -f -- "$t"; exit 2; }
stamp "$1"';

    /**
     * Every entry under "$1", as {@see LIST} prints them but named by their
     * path below it; `{prune}` is where the skipped directories are pruned.
     */
    private const string WALK = self::STAT.'
cd -- "$1" || exit 2
if find . -maxdepth 0 -printf "" >/dev/null 2>&1; then
  exec find . -mindepth 1 {prune} -printf "%Y\t%s\t%T@\t%P\0"
fi
find . -mindepth 1 {prune} -print | while IFS= read -r f; do
  f=${f#./}
  if [ -d "$f" ]; then printf "d\t0\t0\t%s\0" "$f"; continue; fi
  s=$(stamp "$f") || continue
  printf "f\t%s\t%s\t%s\0" "${s% *}" "${s#* }" "$f"
done';

    /** The rest of the arguments, run as a command in "$1". */
    private const string IN_DIRECTORY = 'cd -- "$1" && shift && exec "$@"';

    private const string MKDIR = 'mkdir -p -- "$1"';
    private const string RENAME = 'mkdir -p -- "$(dirname -- "$2")" && mv -f -- "$1" "$2"';
    private const string REMOVE = 'rm -rf -- "$1"';

    /** How long the shared connection outlives its last use, in seconds. */
    private const int PERSIST = 600;

    /**
     * How long to wait to connect, and how a dead connection is noticed: a
     * keepalive every 15 seconds, three unanswered and it is closed. Without
     * them a connection that died with the network — a laptop asleep, a
     * Wi-Fi changed — lingers as the shared one, and every call hangs on it.
     */
    private const array LIVENESS = ['-o', 'ConnectTimeout=10', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=3'];

    /** What mktemp names the temporary an upload goes through: one left behind by a cut is never listed. */
    private const string TEMPORARY = '/^\.porta\.[A-Za-z0-9]{6}$/';

    /** The longest socket path the kernel takes (macOS's is the shortest, 104 with its NUL). */
    private const int SOCKET_PATH_MAX = 103;

    /**
     * @param string|null $controlDir where the shared connections' sockets live — private to this user; null shares none
     * @param string      $ssh        the ssh binary
     */
    public function __construct(
        private readonly RemoteSpec $spec,
        private readonly ?string $controlDir,
        private readonly string $ssh = 'ssh',
    ) {}

    #[\Override]
    public function list(string $path, \Closure $done): void
    {
        $this->script(self::LIST, [$this->spec->remotePath($path)], null, static function (int $code, string $out, string $err) use ($done): void {
            $done(0 === $code ? self::entries($out) : self::failure($code, $err));
        });
    }

    #[\Override]
    public function fetch(string $path, \Closure $done): void
    {
        $this->script(self::FETCH, [$this->spec->remotePath($path)], null, static function (int $code, string $out, string $err) use ($done): void {
            $parts = \explode("\n", $out, limit: 2);
            $stamp = self::stamp($parts[0]);
            $done(0 === $code && null !== $stamp ? new RemoteFile($parts[1] ?? '', $stamp) : self::failure($code, $err));
        });
    }

    #[\Override]
    public function put(string $path, string $bytes, ?RemoteStamp $expected, \Closure $done): void
    {
        $stamp = null === $expected ? '' : $expected->size.' '.$expected->mtime;
        $this->script(self::PUT, [$this->spec->remotePath($path), $stamp, (string) \strlen($bytes)], $bytes, static function (int $code, string $out, string $err) use ($done): void {
            // @noinspection ProperNullCoalescingOperatorUsageInspection -- done() takes the stamp, or the sentence saying why there is none
            $done(match (true) {
                3 === $code => null,
                0 === $code => self::stamp($out) ?? 'the server did not say what it wrote',
                default => self::failure($code, $err),
            });
        });
    }

    /** What ssh says when no key, password or agent got in: `user@host: Permission denied (publickey).` */
    #[\Override]
    public function refusesLogin(string $failure): bool
    {
        return \str_contains($failure, 'Permission denied (');
    }

    #[\Override]
    public function remoteCommand(array $argv): array
    {
        return $this->command(self::IN_DIRECTORY, [$this->spec->remotePath(''), ...$argv]);
    }

    /** One `find` for the whole tree: GNU's `-printf` where there is one, else a `stat` per file. */
    #[\Override]
    public function walk(string $path, array $skip, \Closure $done): bool
    {
        $names = \implode(' -o ', \array_map(static fn (string $name): string => '-name '.self::quote($name), $skip));
        $prune = [] === $skip ? '' : '\\( -type d \\( '.$names.' \\) \\) -prune -o';
        $this->script(\str_replace('{prune}', $prune, self::WALK), [$this->spec->remotePath($path)], null, static function (int $code, string $out, string $err) use ($done): void {
            $done(0 === $code ? self::entries($out) : self::failure($code, $err));
        });

        return true;
    }

    #[\Override]
    public function mkdir(string $path, \Closure $done): void
    {
        $this->script(self::MKDIR, [$this->spec->remotePath($path)], null, self::outcome($done));
    }

    #[\Override]
    public function rename(string $from, string $to, \Closure $done): void
    {
        $this->script(self::RENAME, [$this->spec->remotePath($from), $this->spec->remotePath($to)], null, self::outcome($done));
    }

    /** `rm -rf` serves a file and a directory alike. */
    #[\Override]
    public function remove(string $path, bool $isDir, \Closure $done): void
    {
        $this->script(self::REMOVE, [$this->spec->remotePath($path)], null, self::outcome($done));
    }

    /**
     * The ssh command line that runs $script on the server with $arguments
     * as its `$1`, `$2`…
     *
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    public function command(string $script, array $arguments): array
    {
        $remote = \implode(' ', ['sh', '-c', self::quote($script), 'sh', ...\array_map(self::quote(...), $arguments)]);

        return [$this->ssh, '-o', 'BatchMode=yes', ...self::LIVENESS, ...self::sharing($this->spec, $this->controlDir), '--', self::target($this->spec), $remote];
    }

    /**
     * Where shared connections' sockets live under $home: made, private to
     * this user, when it is not there — or null when it cannot be made, as
     * ssh exits rather than connect when its socket's folder is missing.
     */
    public static function controlDir(string $home): ?string
    {
        $dir = $home.'/.porta/ssh';
        // @mago-expect lint:no-error-control-operator -- a folder that cannot be made only costs the shared connection, which the null says
        if (!\is_dir($dir) && !@\mkdir($dir, permissions: 0o700, recursive: true) && !\is_dir($dir)) {
            return null;
        }

        return $dir;
    }

    /**
     * The options that make ssh share one connection to $spec's server, and
     * reach it on its port. No $controlDir, or one too deep for the kernel's
     * socket path limit — the socket is named by a 40-character hash — shares
     * no connection rather than failing every call.
     *
     * @return list<string>
     */
    public static function sharing(RemoteSpec $spec, ?string $controlDir): array
    {
        $options =
            null !== $controlDir && (\strlen($controlDir) + 41) <= self::SOCKET_PATH_MAX
                ? ['-o', 'ControlMaster=auto', '-o', 'ControlPath='.$controlDir.'/%C', '-o', 'ControlPersist='.self::PERSIST]
                : [];

        return null === $spec->port ? $options : [...$options, '-p', (string) $spec->port];
    }

    /** `user@host`, or the host alone — whose user then comes from ~/.ssh/config, as ssh's own would. */
    public static function target(RemoteSpec $spec): string
    {
        return (null === $spec->user ? '' : $spec->user.'@').$spec->host;
    }

    /** $word as one single-quoted shell word: a `'` inside closes the quote, is escaped, and reopens it. */
    public static function quote(string $word): string
    {
        return "'".\str_replace("'", replace: "'\\''", subject: $word)."'";
    }

    /**
     * @param list<string>                         $arguments
     * @param \Closure(int, string, string): void $done
     */
    private function script(string $script, array $arguments, ?string $stdin, \Closure $done): void
    {
        $this->run($this->command($script, $arguments), $stdin, $done);
    }

    /**
     * @param \Closure(?string): void $done
     *
     * @return \Closure(int, string, string): void
     */
    private static function outcome(\Closure $done): \Closure
    {
        return static function (int $code, string $out, string $err) use ($done): void {
            $done(0 === $code ? null : self::failure($code, $err));
        };
    }

    /** @return list<RemoteEntry> */
    private static function entries(string $listing): array
    {
        $entries = [];
        foreach (\explode("\0", $listing) as $record) {
            $fields = \explode("\t", $record, limit: 4);
            if (4 !== \count($fields) || 1 === \preg_match(self::TEMPORARY, \basename($fields[3]))) {
                continue;
            }
            $entries[] = new RemoteEntry($fields[3], 'd' === $fields[0], new RemoteStamp((int) $fields[1], (int) $fields[2]));
        }

        return $entries;
    }

    /** `size mtime` — what the scripts print — or null when it is not that. */
    private static function stamp(string $line): ?RemoteStamp
    {
        $match = [];

        return 1 === \preg_match('/^(\d+) (\d+)(?:\.\d+)?\s*$/', $line, $match) ? new RemoteStamp((int) $match[1], (int) $match[2]) : null;
    }
}
