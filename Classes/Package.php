<?php
namespace ElementareTeilchen\Neos\ExternalRedirect;

/*
 * This file is part of the ElementareTeilchen.Neos.ExternalRedirect package.
 *
 * (c) elementare teilchen GmbH - www.elementare-teilchen.de
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use ElementareTeilchen\Neos\ExternalRedirect\Service\ExternalUrlRedirectService;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
use Neos\ContentRepository\Domain\Model\Workspace;

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
