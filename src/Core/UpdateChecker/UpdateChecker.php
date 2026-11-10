<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\UpdateChecker;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

class UpdateChecker
{

    public function __construct(
        private readonly string $repoUrl,
        private readonly string $pluginFile,
        private readonly string $slug,
    ) {
        add_action('plugins_loaded', [$this, 'checkUpdates']);
    }

    public function checkUpdates() : void
    {

        $updateChecker = PucFactory::buildUpdateChecker(
            $this->repoUrl,
            $this->pluginFile,
            $this->slug
        );

        // Update from GitHub release assets, not from the branch head.
        $updateChecker->getVcsApi()->enableReleaseAssets();

    }
}
