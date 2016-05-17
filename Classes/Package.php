<?php
namespace ElementareTeilchen\Neos\ExternalRedirect;

/*
 * This file is part of the Neos.RedirectHandler.NeosAdapter package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use ElementareTeilchen\Neos\ExternalRedirect\Service\ExternalUrlRedirectService;
use TYPO3\Flow\Core\Bootstrap;
use TYPO3\Flow\Package\Package as BasePackage;
use TYPO3\TYPO3CR\Domain\Model\Workspace;

/**
 * The ElementareTeilchen Neos ExternalRedirect Package
 */
class Package extends BasePackage
{
    /**
     * @param Bootstrap $bootstrap The current bootstrap
     * @return void
     */
    public function boot(Bootstrap $bootstrap)
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();

        $dispatcher->connect(Workspace::class, 'beforeNodePublishing', ExternalUrlRedirectService::class, 'createRedirectsForPublishedNode');
    }
}
