# SSH

Opens a project that lives on a server, over ssh, and edits it as though it
were here. Enabled by default; it does nothing until a remote project is open.

```sh
porta ssh deploy@example.org:/srv/app
porta ssh ssh://deploy@example.org:2222/srv/app
porta ssh example.org:sites/app        # relative to your login directory
```

`porta ssh` connects on your terminal first, so a passphrase, a password or a
host key seen for the first time is answered there. That connection stays open
(ssh's `ControlMaster`, ten minutes after its last use) and the IDE shares it:
every file it lists, fetches or saves rides it, one round trip each. Your
`~/.ssh/config` applies, so a host alias works as the host.

You can also open one from inside Porta with **File → Open Remote Project…**.
The IDE never prompts, though, so a server that needs a password has to be
connected with `porta ssh` first. A server your keys or ssh-agent let you into
works from either.

## How the project is kept

The project is kept in `~/.porta/remote/`, as a cache:

- A directory is listed the first time the tree shows it. Every file in it
  appears at once, but as an empty placeholder.
- A file is fetched the first time you open it.
- A save writes the cache, then sends the file to the server in the
  background.
- Porta re-lists, every few seconds, the directories holding your open files
  and the ones you expanded. A file someone changes on the server reloads like
  any file changed on disk, and warns first if you have unsaved edits.

Saves are refused when the file changed on the server since you fetched it.
The toast says so, and saving again overwrites. Quitting while changes are
still on their way to the server asks twice, like quitting with unsaved edits.

## What runs on this machine

The cache holds what you opened, not the project. So nothing that would run
the project here is offered:

- language servers stay off;
- **Run**, the terminal, the debugger and the analysers and formatters say the
  project is on the server.

Find in Files searches only the files you have opened.

The server needs only `sh` and the standard tools: `find` or `stat`, `cat`,
`mv`, `mkdir`, `rm` and `mktemp`.

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| ssh binary | `ssh` | The ssh client to run: a name on `PATH`, or a full path |
