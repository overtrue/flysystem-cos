<?php

namespace Overtrue\Flysystem\Cos\Plugins;

use League\Flysystem\Plugin\AbstractPlugin;

class FileSignedUrl extends AbstractPlugin
{
    public function getMethod()
    {
        return 'getSignedUrl';
    }

    public function handle(string $path, string $expires = null)
    {
        return $this->filesystem->getAdapter()->getSignedUrl($path, $expires);
    }
}
