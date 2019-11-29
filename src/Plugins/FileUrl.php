<?php


namespace Overtrue\Flysystem\Cos\Plugins;

use League\Flysystem\Plugin\AbstractPlugin;

class FileUrl extends AbstractPlugin
{
    public function getMethod()
    {
        return 'getUrl';
    }

    public function handle($path)
    {
        return $this->filesystem->getAdapter()->getUrl($path);
    }
}