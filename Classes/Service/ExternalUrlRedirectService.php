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
        if ($targetNode === null) {
            // The page has been newly added, then we dont have targetNodeUriPath
            // todo: is it important to be able to save external redirects on page creation?
            return;
        }

        //only keep going if redirect field has changed
        if ($node->getProperty('redirectUrls') == $targetNode->getProperty('redirectUrls')) {
            return;
        }
        $targetNodeUriPath = $this->buildUriPathForNodeContextPath($targetNode->getContextPath());
        if ($targetNodeUriPath === null) {
            throw new Exception('The target URI path of the node could not be resolved', 1451945358);
        }

        $hosts = $this->getHostPatterns($node->getContext());

        $this->flushRoutingCacheForNode($targetNode);
        $statusCode = (integer)$this->defaultStatusCode['redirect'];
        // split by any whitespace
        $redirectUrlsArray = preg_split('/\s+/', $node->getProperty('redirectUrls'));
        foreach ($redirectUrlsArray as $redirectUrl) {
            $urlPathOnly = parse_url(trim($redirectUrl),PHP_URL_PATH);

            if ($node->isRemoved()) {
                $this->redirectStorage->removeOneBySourceUriPathAndHost($urlPathOnly, $hosts);
                continue;
            }

            if ($this->redirectStorage->getOneBySourceUriPathAndHost($urlPathOnly)) {
                // todo: check if set on other page / with other target and warning to editor?

                // if from this page, just do nothing
                continue;
            }
            $this->redirectStorage->addRedirect($urlPathOnly, $targetNodeUriPath, $statusCode, $hosts);
        }

    }
}
