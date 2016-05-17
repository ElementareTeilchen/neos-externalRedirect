<?php
namespace ElementareTeilchen\Neos\ExternalRedirect\Service;

/*
 * This file is part of the ElementareTeilchen.Neos.ExternalRedirect package.
 * Inspired, copied and modified from Neos.RedirectHandler.NeosAdapter.
 * Thanks to Dominique Feyer!
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Neos\Routing\Exception;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;
use TYPO3\TYPO3CR\Domain\Model\Workspace;

/**
 * Service that creates redirects for given external urls in inspector field in nodes to the node in which the external url is saved.
 *
 * Note: This is usually invoked by a signal emitted by Workspace::publishNode()
 *
 * @Flow\Scope("singleton")
 */
class ExternalUrlRedirectService extends \Neos\RedirectHandler\NeosAdapter\Service\NodeRedirectService
{
    /**
     * this slot is called after the very similar slot in Neos.RedirectHandler.NeosAdapter
     * we deliberately depend on that package, which does already lots of stuff needed (like clearing redirect cache)
     * and add only needed stuff for our use case

     * @param NodeInterface $node
     * @param Workspace $targetWorkspace
     * @throws Exception
     */
    public function createRedirectsForPublishedNode(NodeInterface $node, Workspace $targetWorkspace)
    {
        $this->systemLogger->log('22'.$node->getProperty('redirectUrls'));
        $nodeType = $node->getNodeType();

        // only act if a Document node is published to live workspace
        if ($targetWorkspace->getName() !== 'live' || !$nodeType->isOfType('TYPO3.Neos:Document')) {
            return;
        }

        $context = $this->contextFactory->create([
            'workspaceName' => 'live',
            'invisibleContentShown' => true,
            'dimensions' => $node->getDimensions()
        ]);
        $targetNode = $context->getNodeByIdentifier($node->getIdentifier());

        //compare if redirect field has changed
        //todo: is this correct??
        if ($node->getProperty('redirectUrls') == $targetNode->getProperty('redirectUrls')) {
            return;
        }
        $targetNodeUriPath = $this->buildUriPathForNodeContextPath($targetNode->getContextPath());
        if ($targetNodeUriPath === null) {
            throw new Exception('The target URI path of the node could not be resolved', 1451945358);
        }

        $hosts = $this->getHostPatterns($node->getContext());

        // The page has been removed
        if ($node->isRemoved()) {
            // todo remove old external redirects from redirect table
            #$this->redirectStorage->addRedirect($targetNodeUriPath, '', $statusCode, $hosts);
            return;
        }


        $this->flushRoutingCacheForNode($targetNode);
        $statusCode = (integer)$this->defaultStatusCode['redirect'];
        foreach (explode('\n',$node->getProperty('redirectUrls')) as $redirectUrl) {
            $this->redirectStorage->addRedirect($targetNodeUriPath, trim($redirectUrl), $statusCode, $hosts);
        }

    }
}
