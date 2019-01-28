<?php
namespace ElementareTeilchen\Neos\ExternalRedirect;

use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;

class PendingRedirect
{
    /**
     * @var array
     */
    protected $dimensions;

    /**
     * @var string
     */
    protected $nodeIdentifier;

    /**
     * @var string
     */
    protected $oldRedirectUrls;

    /**
     * @var string
     */
    protected $workspaceName;


    /**
     * @Flow\Inject
     *
     * @var ContentContextFactory
     */
    protected $contentContextFactory;


    /**
     * @param array $dimensions
     * @param string $nodeIdentifier
     * @param string $oldRedirectUrls
     * @param string $workspaceName
     */
    public function __construct(
        array $dimensions,
        string $nodeIdentifier,
        string $oldRedirectUrls,
        string $workspaceName
    ) {
        $this->dimensions = $dimensions;
        $this->nodeIdentifier = $nodeIdentifier;
        $this->oldRedirectUrls = $oldRedirectUrls;
        $this->workspaceName = $workspaceName;
    }

    /**
     * @return array
     */
    public function getDimensions() : array
    {
        return $this->dimensions;
    }

    /**
     * @return string
     */
    public function getNodeIdentifier() : string
    {
        return $this->nodeIdentifier;
    }

    /**
     * @return string
     */
    public function getOldRedirectUrls() : string
    {
        return $this->oldRedirectUrls;
    }

    /**
     * @return string
     */
    public function getWorkspaceName() : string
    {
        return $this->workspaceName;
    }


    /**
     * @return ContentContext
     */
    public function createContentContext() : ContentContext
    {
        $contentContext = $this->contentContextFactory->create([
            'dimensions' => $this->dimensions,
            'invisibleContentShown' => true,
            'workspaceName' => $this->workspaceName,
        ]);
        assert($contentContext instanceof ContentContext);
        return $contentContext;
    }
}
