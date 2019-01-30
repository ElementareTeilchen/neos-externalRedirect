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

use ElementareTeilchen\Neos\ExternalRedirect\PendingRedirect;
use Neos\ContentRepository\Domain\Factory\NodeFactory;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Exception\NodeException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Request;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Mvc\Routing\RouterCachingService;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\RedirectHandler\DatabaseStorage\Domain\Model\Redirect;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;

/**
 * Service that creates redirects for given external urls in a node property to the node in which the external url is
 * saved.
 *
 * @Flow\Scope("singleton")
 */
class ExternalUrlRedirectService
{
    /**
     * The name of the mixin containing the redirect urls property
     *
     * @var string
     */
    public const REDIRECT_URLS_MIXIN = 'ElementareTeilchen.Neos.ExternalRedirect:RedirectUrlsMixin';

    /**
     * The name of the property containing the redirect urls
     *
     * @var string
     */
    public const REDIRECT_URLS_PROPERTY = 'redirectUrls';


    /**
     * @Flow\InjectConfiguration(path="createForAllHosts", package="ElementareTeilchen.Neos.ExternalRedirect")
     *
     * @var bool
     */
    protected $createRedirectForAllHosts;

    /**
     * @Flow\Inject
     *
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     *
     * @var NodeFactory
     */
    protected $nodeFactory;

    /**
     * @var array<PendingRedirect>
     */
    protected $pendingRedirects = [];

    /**
     * @Flow\Inject
     *
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\InjectConfiguration(path="statusCode", package="ElementareTeilchen.Neos.ExternalRedirect")
     *
     * @var int
     */
    protected $redirectStatusCode;

    /**
     * @Flow\Inject
     *
     * @var RedirectStorageInterface
     */
    protected $redirectStorage;

    /**
     * @Flow\Inject
     *
     * @var RouterCachingService
     */
    protected $routerCachingService;

    /**
     * @var UriBuilder
     */
    protected $uriBuilder;


    /**
     * @param ContentContext $contentContext
     *
     * @return array<string>
     */
    protected static function extractAllHostsFromContentContext(ContentContext $contentContext) : array
    {
        $hosts = [];
        $site = $contentContext->getCurrentSite();
        if ($site !== null) {
            foreach ($site->getActiveDomains() as $domain) {
                \assert($domain instanceof Domain);
                $hosts[] = $domain->getHostname();
            }
        }
        return $hosts;
    }

    /**
     * @param string $redirectUrls
     *
     * @return array<string>
     */
    protected static function splitUrlPathsByWhitespace(string $redirectUrls) : array
    {
        $redirectUrlsArray = \preg_split('/\s+/', $redirectUrls);
        \array_walk(
            $redirectUrlsArray,
            static function (string &$redirectUrl) {
                $redirectUrl = \trim(\parse_url(\trim($redirectUrl), \PHP_URL_PATH), '/');
            }
        );

        return $redirectUrlsArray;
    }


    /**
     * @return void
     */
    public function initializeObject() : void
    {
        $this->uriBuilder = new UriBuilder();
        try {
            $this->uriBuilder->setRequest(new ActionRequest(Request::createFromEnvironment()));
        } catch (\InvalidArgumentException $exception) {
            // HttpRequest is hardcoded above
        }
        $this->uriBuilder->setFormat('html')->setCreateAbsoluteUri(false);
    }


    /**
     * Collects the node for redirection if it is a 'ElementareTeilchen.Neos.ExternalRedirect:RedirectUrlsMixin' node
     *
     * @param NodeInterface $node The node that is about to be published
     * @param Workspace $targetWorkspace
     *
     * @return void
     */
    public function collectPossibleRedirects(NodeInterface $node, Workspace $targetWorkspace) : void
    {
        if (
            $targetWorkspace->isPublicWorkspace() === false
            || $node->getNodeType()->isOfType(static::REDIRECT_URLS_MIXIN) === false
        ) {
            return;
        }
        $this->appendNodeToPendingRedirects($node, $targetWorkspace);
    }

    /**
     * Creates the queued redirects provided we can find the node.
     *
     * @return void
     */
    public function createPendingRedirects() : void
    {
        $this->nodeFactory->reset();
        foreach ($this->pendingRedirects as $pendingRedirect) {
            \assert($pendingRedirect instanceof PendingRedirect);
            $contentContext = $pendingRedirect->createContentContext();
            $node = $contentContext->getNodeByIdentifier($pendingRedirect->getNodeIdentifier());
            if ($node !== null) {
                $oldUrlPaths = static::splitUrlPathsByWhitespace($pendingRedirect->getOldRedirectUrls());
                try {
                    $newUrlPaths = static::splitUrlPathsByWhitespace(
                        $node->getProperty(static::REDIRECT_URLS_PROPERTY)
                    );
                } catch (NodeException $exception) {
                    // The property not existing is like the property being empty
                    $newUrlPaths = [];
                }
                $hosts = $this->createRedirectForAllHosts
                    ? [null]
                    : static::extractAllHostsFromContentContext($contentContext)
                ;
                $nodeUriPath = $this->buildUriPathForNode($node);
                $removedRedirects = $this->removeRedirects(
                    \array_diff($oldUrlPaths, $newUrlPaths),
                    $nodeUriPath,
                    $hosts
                );
                $createdRedirects = $this->createRedirects(
                    \array_diff($newUrlPaths, $oldUrlPaths),
                    $nodeUriPath,
                    $hosts
                );
                if ($removedRedirects > 0 || $createdRedirects !== []) {
                    $this->flushRoutingCacheForNode($node);
                }
            }
        }
    }

    /**
     * @param NodeInterface $node
     *
     * @return bool
     */
    public function createRedirectsForNode(NodeInterface $node) : bool
    {
        $nodeUriPath = $this->buildUriPathForNode($node);

        try {
            $nodeRedirectUrls = $node->getProperty(static::REDIRECT_URLS_PROPERTY);
        } catch (NodeException $exception) {
            $nodeRedirectUrls = '';
        }
        if (empty($nodeRedirectUrls)) {
            return false;
        }

        $uriPaths = static::splitUrlPathsByWhitespace($nodeRedirectUrls);
        $uriPathsToCreateRedirectFor = [];
        foreach ($uriPaths as $uriPath) {
            if ($uriPath === '') {
                continue;
            }

            $existingRedirect = $this->redirectStorage->getOneBySourceUriPathAndHost($uriPath);
            if ($existingRedirect === null) {
                $uriPathsToCreateRedirectFor[] = $uriPath;
            }
        }

        if ($uriPathsToCreateRedirectFor === []
            || $this->createRedirects($uriPathsToCreateRedirectFor, $nodeUriPath) === []
        ) {
            return false;
        }

        $this->flushRoutingCacheForNode($node);

        return true;
    }


    /**
     * @param NodeInterface $node
     * @param Workspace $targetWorkspace
     *
     * @return void
     */
    protected function appendNodeToPendingRedirects(NodeInterface $node, Workspace $targetWorkspace) : void
    {
        $oldRedirectUrls = '';
        $targetNodeData = $this->findCorrespondingNodeDataInTargetWorkspace($node, $targetWorkspace);
        if ($targetNodeData !== null) {
            try {
                $oldRedirectUrls = \trim($targetNodeData->getProperty(static::REDIRECT_URLS_PROPERTY));
            } catch (NodeException $exception) {
                // if property doesn't exist, it's the same as being empty
            }
        }
        $this->pendingRedirects[] = new PendingRedirect(
            $node->getContext()->getDimensions(),
            $node->getIdentifier(),
            $oldRedirectUrls,
            $targetWorkspace->getName()
        );
    }

    /**
     * Creates a (relative) URI for the given $nodeContextPath removing the "@workspace-name" from the result
     *
     * @param NodeInterface $node
     *
     * @return string the resulting (relative) URI
     */
    protected function buildUriPathForNode(NodeInterface $node) : string
    {
        try {
            $uri = $this->uriBuilder->uriFor('show', ['node' => $node], 'Frontend\\Node', 'Neos.Neos');

            if (\strpos($uri, './') === 0) {
                $uri = \substr($uri, 2);
            } elseif (\strpos($uri, '/') === 0) {
                $uri = \substr($uri, 1);
            }

            return $uri;
        } catch (MissingActionNameException $exception) {
            // Action name is hardcoded above
            return '';
        }
    }

    /**
     * @param array<string> $uriPaths
     * @param string $nodeUriPath
     * @param array<string> $hosts
     *
     * @return array<Redirect>
     */
    protected function createRedirects(array $uriPaths, string $nodeUriPath, array $hosts = [null]) : array
    {
        $createdRedirects = [];
        foreach ($uriPaths as $uriPath) {
            if ($uriPath === '') {
                continue;
            }

            $createdRedirects[] = $this->redirectStorage->addRedirect(
                $uriPath,
                $nodeUriPath,
                $this->redirectStatusCode,
                $hosts
            );
        }

        if ($createdRedirects !== []) {
            $createdRedirects = \array_merge(...$createdRedirects);
            $this->persistenceManager->persistAll();
        }

        return $createdRedirects;
    }

    /**
     * Returns the NodeData instance with the given identifier from the target workspace.
     * If no NodeData instance is found in that target workspace, null is returned.
     *
     * @param NodeInterface $node The reference node to find a corresponding variant for
     * @param Workspace $targetWorkspace The target workspace to look in
     *
     * @return NodeData|null Either a regular node, a shadow node or null
     */
    protected function findCorrespondingNodeDataInTargetWorkspace(
        NodeInterface $node,
        Workspace $targetWorkspace
    ) : ?NodeData {
        $nodeData = $this->nodeDataRepository->findOneByIdentifier(
            $node->getIdentifier(),
            $targetWorkspace,
            $node->getDimensions(),
            true
        );
        if ($nodeData === null || $nodeData->getWorkspace() !== $targetWorkspace) {
            return null;
        }
        return $nodeData;
    }

    /**
     * @param NodeInterface $node
     *
     * @return void
     */
    protected function flushRoutingCacheForNode(NodeInterface $node) : void
    {
        $nodeDataIdentifier = $this->persistenceManager->getIdentifierByObject($node->getNodeData());
        if ($nodeDataIdentifier !== null) {
            $this->routerCachingService->flushCachesByTag($nodeDataIdentifier);
        }
    }

    /**
     * @param array<string> $uriPaths
     * @param string $nodeUriPath
     * @param array<string> $hosts
     *
     * @return int
     */
    protected function removeRedirects(array $uriPaths, string $nodeUriPath, array $hosts = [null]) : int
    {
        $removedRedirects = 0;
        foreach ($uriPaths as $uriPath) {
            foreach ($hosts as $host) {
                $redirect = $this->redirectStorage->getOneBySourceUriPathAndHost($uriPath, $host);
                if ($redirect !== null && $redirect->getTargetUriPath() === $nodeUriPath) {
                    $this->redirectStorage->removeOneBySourceUriPathAndHost($uriPath, $host);
                    $removedRedirects++;
                }
            }
        }
        if ($removedRedirects > 0) {
            $this->persistenceManager->persistAll();
        }
        return $removedRedirects;
    }
}
