<?php

declare(strict_types=1);

use Porta\Config\PortaHome;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteTransport;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\RemoteProjectPlugin;
use Porta\SshPlugin\SshTransport;

/**
 * Projects on a server, over ssh: `porta ssh user@host:/path` opens one, and
 * while it is open this plugin moves its files. Core keeps the cache and
 * decides what to fetch and when ({@see \Porta\Filesystem\RemoteFilesystem});
 * {@see SshTransport} only lists, reads and writes.
 *
 * Inert for a project on this machine: nothing here runs unless the project
 * open is an `ssh://` or `sftp://` one.
 */
return new class('ssh') extends AbstractPlugin implements RemoteProjectPlugin {
    private ?PluginApi $api = null;

    public function activate(PluginApi $api): void
    {
        $api->registerSetting('binary', 'ssh binary', 'text', 'ssh', hint: 'the ssh client to run; a name on PATH or a full path');
        $this->api = $api;
    }

    public function remoteTransport(RemoteSpec $spec): ?RemoteTransport
    {
        $home = PortaHome::base();
        if (!\in_array($spec->scheme, ['ssh', 'sftp'], strict: true) || null === $home) {
            return null;
        }

        $binary = \trim((string) $this->api?->setting('binary'));

        return new SshTransport($spec, SshTransport::controlDir($home), '' === $binary ? 'ssh' : $binary);
    }
};
