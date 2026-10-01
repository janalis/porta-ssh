<?php

declare(strict_types=1);

namespace Porta\SshPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\RemoteSpec;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\RemoteProjectPlugin;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\SshPlugin\SshTransport;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** The plugin serves ssh:// and sftp:// projects, over the ssh its setting names. */
final class SshPluginTest extends TestCase
{
    use PreservesPortaHome;

    private function plugin(): RemoteProjectPlugin
    {
        $settings = new PluginSettings('/p', ['ssh' => ['binary' => '/opt/bin/ssh']]);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $api = new PluginApi('ssh', $manager, '/p', $settings);
        $plugin = require \dirname(__DIR__).'/plugin.php';
        $this->assertInstanceOf(RemoteProjectPlugin::class, $plugin, 'the ssh plugin opens remote projects');
        $plugin->activate($api);
        $manager->commit($api, $plugin);

        return $plugin;
    }

    public function testItServesSshAndSftpOverTheConfiguredBinary(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        $plugin = $this->plugin();

        $transport = $plugin->remoteTransport(new RemoteSpec('ssh', 'example.org', '/srv'));

        $this->assertInstanceOf(SshTransport::class, $transport);
        $this->assertSame('/opt/bin/ssh', $transport->command('true', [])[0]);
        $this->assertSame($home->path('.porta/ssh'), Probe::get($transport, 'controlDir'));
        $this->assertInstanceOf(SshTransport::class, $plugin->remoteTransport(new RemoteSpec('sftp', 'example.org', '/srv')));
        $this->assertNull($plugin->remoteTransport(new RemoteSpec('ftp', 'example.org', '/srv')), 'not its scheme');
    }

    public function testWithoutAHomeThereIsNowhereToShareAConnection(): void
    {
        \putenv('PORTA_HOME');
        $home = \getenv('HOME');
        \putenv('HOME');
        try {
            $this->assertNull($this->plugin()->remoteTransport(new RemoteSpec('ssh', 'example.org', '/srv')));
        } finally {
            \putenv('HOME='.$home);
        }
    }
}
