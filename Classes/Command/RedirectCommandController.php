<?php
namespace ElementareTeilchen\Neos\ExternalRedirect\Command;

use ElementareTeilchen\Neos\ExternalRedirect\Service\ExternalUrlRedirectService;
use Neos\ContentRepository\Domain\Factory\NodeFactory;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\ContextFactory;
use Neos\ContentRepository\Exception\NodeConfigurationException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Mvc\Exception\NoMatchingRouteException;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;

/**
 * @Flow\Scope("singleton")
 */
class RedirectCommandController extends CommandController
{
    /**
     * @var ContentDimensionPresetSourceInterface
     *
     * @Flow\Inject
     */
    protected $contentDimensionPresetSource;

    /**
     * @var ContextFactory
     *
     * @Flow\Inject
     */
    protected $contextFactory;

    /**
     * @var ExternalUrlRedirectService
     *
     * @Flow\Inject
     */
    protected $externalUrlRedirectService;

    /**
     * @var NodeDataRepository
     *
     * @Flow\Inject
     */
    protected $nodeDataRepository;

    /**
     * @var NodeFactory
     *
     * @Flow\Inject
     */
    protected $nodeFactory;

    /**
     * @var WorkspaceRepository
     *
     * @Flow\Inject
     */
    protected $workspaceRepository;


    /**
     * Generate external redirects from Node properties
     *
     * @return void
     *
     * @throws NodeConfigurationException
     */
    public function generateExternalCommand() : void
    {
        \putenv('FLOW_REWRITEURLS=1');
        /** @var Workspace $liveWorkspace */
        $liveWorkspace = $this->workspaceRepository->findByIdentifier('live');
        $contentDimensionPresetSources = $this->contentDimensionPresetSource->getAllPresets();
        foreach ($contentDimensionPresetSources as $contentDimensionName => ['presets' => $contentDimensionPresets]) {
            foreach ($contentDimensionPresets as ['values' => $contentDimensionPresetValues]) {
                $context = $this->contextFactory->create([
                    'dimensions' => [$contentDimensionName => $contentDimensionPresetValues],
                ]);
                /** @var NodeData[] $nodeDatas */
                $nodeDatas = $this->nodeDataRepository->findByParentAndNodeTypeRecursively(
                    '/sites',
                    'ElementareTeilchen.Neos.ExternalRedirect:RedirectUrlsMixin',
                    $liveWorkspace,
                    [$contentDimensionName => $contentDimensionPresetValues]
                );
                foreach ($nodeDatas as $nodeData) {
                    $nodeInterface = $this->nodeFactory->createFromNodeData($nodeData, $context);
                    if ($nodeInterface === null) {
                        continue;
                    }

                    try {
                        if ($this->externalUrlRedirectService->createRedirectsForNode($nodeInterface)) {
                            $this->outputLine(
                                'Weiterleitungen für Node ' . $nodeInterface->getContextPath() . ' aktualisiert'
                            );
                        }
                    } catch (NoMatchingRouteException $exception) {
                        $this->outputLine(
                            'ERROR creating redirect for node %s: %s',
                            [
                                $nodeInterface->getContextPath(),
                                $exception->getMessage(),
                            ]
                        );
                    }
                }
            }
        }
    }
}
