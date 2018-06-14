<?php
namespace ElementareTeilchen\Neos\ExternalRedirect\Service;

/*
 * This file is part of the ElementareTeilchen.Neos.ExternalRedirect package.
 * Inspired, copied and modified from Neos.RedirectHandler.NeosAdapter.
 * Thanks to Dominique Feyer!
 *
 * (c) elementare teilchen GmbH - www.elementare-teilchen.de
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

// use ElementareTeilchen\Neos\ExternalRedirect\DuplicateRedirectException;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Annotations as Flow;
// use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc\Exception\NoMatchingRouteException;
use Neos\RedirectHandler\DatabaseStorage\Domain\Model\Redirect;
use Neos\RedirectHandler\DatabaseStorage\Domain\Repository\RedirectRepository;
use Neos\RedirectHandler\NeosAdapter\Service\NodeRedirectService;

/**
 * Service that creates redirects for given external urls in inspector field in nodes to the node in which the external url is saved.
 *
 * Note: This is usually invoked by a signal emitted by Workspace::publishNode()
 *
 * @Flow\Scope("singleton")
 */
class ExternalUrlRedirectService extends NodeRedirectService
{
    /**
     * @var int
     *
     * @Flow\InjectConfiguration(path="statusCode", package="ElementareTeilchen.Neos.ExternalRedirect")
     */
    protected $defaultExternalStatusCode;

    /**
     * @var RedirectRepository
     *
     * @Flow\Inject
     */
    protected $redirectRepository;

    // /**
    //  * @Flow\Inject
    //  * @var Translator
    //  */
    // protected $translator;

    /**
     * this slot is called after the very similar slot in Neos.RedirectHandler.NeosAdapter
     * we deliberately depend on that package, which does already lots of stuff needed (like clearing redirect cache)
     * and add only needed stuff for our use case

     * @param NodeInterface $node
     * @param Workspace $targetWorkspace
     * @throws NoMatchingRouteException
     */
    public function createRedirectsForPublishedNode(NodeInterface $node, Workspace $targetWorkspace)
    {
        $nodeType = $node->getNodeType();

        // only act if a Document node is published to live workspace
        if ($targetWorkspace->getName() !== 'live' || !$nodeType->isOfType('Neos.Neos:Document')) {
            return;
        }

        $context = $this->contextFactory->create([
            'workspaceName' => 'live',
            'invisibleContentShown' => true,
            'dimensions' => $node->getContext()->getDimensions(),
        ]);
        $targetNode = $context->getNodeByIdentifier($node->getIdentifier());
        if ($targetNode === null) {
            // The page has been newly added, then we dont have targetNodeUriPath
            // todo: is it important to be able to save external redirects on page creation?
            return;
        }

        $nodeRedirectUrls = $node->getProperty('redirectUrls');
        $targetNodeRedirectUrls = $targetNode->getProperty('redirectUrls');
        //only keep going if redirect field has changed
        if ($nodeRedirectUrls === $targetNodeRedirectUrls) {
            return;
        }

        $targetNodeUriPath = $this->buildUriPathForNodeContextPath($targetNode->getContextPath());
        if ($targetNodeUriPath === null) {
            throw new NoMatchingRouteException('The target URI path of the node could not be resolved', 1451945358);
        }

        $hosts = $this->getHostnames($node->getContext());
        if ($hosts === []) {
            $hosts[] = null;
        }

        $this->flushRoutingCacheForNode($targetNode);
        $statusCode = $this->defaultExternalStatusCode ?? (int)$this->defaultStatusCode['redirect'];
        // split by any whitespace
        $redirectUrlsArrayOld = preg_split('/\s+/', $targetNodeRedirectUrls);
        \array_walk($redirectUrlsArrayOld, function (&$redirectUrl) {
            $redirectUrl = \trim(\parse_url(\trim($redirectUrl), PHP_URL_PATH), '/');
        });
        $redirectUrlsArray = preg_split('/\s+/', $nodeRedirectUrls);
        \array_walk($redirectUrlsArray, function (&$redirectUrl) {
            $redirectUrl = \trim(\parse_url(\trim($redirectUrl), PHP_URL_PATH), '/');
        });
        $removedUrls = array_diff($redirectUrlsArrayOld, $redirectUrlsArray);


        // first remove all urls which have been set earlier, but not any more -> were removed by editor just now
        foreach ($removedUrls as $redirectUrl) {
            foreach ($hosts as $host) {
                $this->redirectStorage->removeOneBySourceUriPathAndHost($redirectUrl, $host);
            }
        }

        // check/add the current urls
        foreach ($redirectUrlsArray as $redirectUrl) {
            if ($redirectUrl === '') {
                continue;
            }

            if ($node->isRemoved()) {
                foreach ($hosts as $host) {
                    $this->redirectStorage->removeOneBySourceUriPathAndHost($redirectUrl, $host);
                }
                continue;
            }

            $shouldAddRedirect = false;
            $hostsToAddRedirectTo = [];
            foreach ($hosts as $host) {
                $existingRedirect = $this->redirectStorage->getOneBySourceUriPathAndHost($redirectUrl, $host, false);
                if ($existingRedirect === null) {
                    $shouldAddRedirect = true;
                    if ($host !== null) {
                        $hostsToAddRedirectTo[] = $host;
                    }
                // } elseif (trim($existingRedirect->getTargetUriPath(), '/') !== trim($targetNodeUriPath, '/')) {
                    // TODO: we need the exception message to be visible in production context to show editors what's wrong
                    // http://flowframework.readthedocs.io/en/stable/TheDefinitiveGuide/PartIII/ErrorAndExceptionHandling.html
                    // skip exception for now
                    /*
                    throw new DuplicateRedirectException($this->translator->translateById('exception.redirectExists', [
                        'source' => $redirectUrl,
                        'newTarget' => $targetNodeUriPath,
                        'existingTarget' => $existingRedirect->getTargetUriPath()
                    ], null, null, 'Main', 'ElementareTeilchen.Neos.ExternalRedirect'), 201607051029);
                    */
                }
            }

            if ($shouldAddRedirect) {
                $this->redirectStorage->addRedirect($redirectUrl, $targetNodeUriPath, $statusCode, $hostsToAddRedirectTo);
            }
        }
    }

    /** @noinspection PhpDocMissingThrowsInspection */
    /**
     * @param NodeInterface $node
     *
     * @return bool
     *
     * @throws NoMatchingRouteException
     */
    public function createRedirectsForNode(NodeInterface $node) : bool
    {
        $nodeUriPath = $this->buildUriPathForNodeContextPath($node->getContextPath());
        if (\strpos($nodeUriPath, './') === 0) {
            $nodeUriPath = \substr($nodeUriPath, 2);
        }
        if ($nodeUriPath === null) {
            throw new NoMatchingRouteException('The target URI path of the node could not be resolved', 1528980367020);
        }

        /** @var \Generator $existingRedirectsForTarget */
        $existingRedirectsForTarget = $this->redirectRepository->findByTargetUriPathAndHost($nodeUriPath);

        /** @noinspection PhpUnhandledExceptionInspection */
        $nodeRedirectUrls = $node->getProperty('redirectUrls');
        if (empty($nodeRedirectUrls) && !$existingRedirectsForTarget->valid()) {
            return false;
        }
        // split by any whitespace
        $redirectUrlsArray = \preg_split('/\s+/', $nodeRedirectUrls);
        \array_walk($redirectUrlsArray, function (&$redirectUrl) {
            $redirectUrl = \trim(\parse_url(\trim($redirectUrl), PHP_URL_PATH), '/');
        });

        $routingForNodeChanged = false;

        foreach ($existingRedirectsForTarget as $existingRedirect) {
            /** @var Redirect $existingRedirect */
            if (!\in_array($existingRedirect->getSourceUriPath(), $redirectUrlsArray, true)) {
                $this->redirectStorage->removeOneBySourceUriPathAndHost($existingRedirect->getSourceUriPath());
                $routingForNodeChanged = true;
            }
        }

        $statusCode = $this->defaultExternalStatusCode ?? (int)$this->defaultStatusCode['redirect'];

        foreach ($redirectUrlsArray as $redirectUrl) {
            if ($redirectUrl === '') {
                continue;
            }

            $existingRedirect = $this->redirectStorage->getOneBySourceUriPathAndHost($redirectUrl);
            if ($existingRedirect === null) {
                $this->redirectStorage->addRedirect($redirectUrl, $nodeUriPath, $statusCode);
                $routingForNodeChanged = true;
                // } elseif (trim($existingRedirect->getTargetUriPath(), '/') !== trim($nodeUriPath, '/')) {
                // TODO: we need the exception message to be visible in production context to show editors what's wrong
                // http://flowframework.readthedocs.io/en/stable/TheDefinitiveGuide/PartIII/ErrorAndExceptionHandling.html
                // skip exception for now
                /*
                throw new DuplicateRedirectException($this->translator->translateById('exception.redirectExists', [
                    'source' => $redirectUrl,
                    'newTarget' => $nodeUriPath,
                    'existingTarget' => $existingRedirect->getTargetUriPath()
                ], null, null, 'Main', 'ElementareTeilchen.Neos.ExternalRedirect'), 201607051029);
                */
            }
        }

        if ($routingForNodeChanged) {
            $this->flushRoutingCacheForNode($node);
        }
        return $routingForNodeChanged;
    }
}
