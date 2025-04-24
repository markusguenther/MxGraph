<?php
namespace Sandstorm\MxGraph\Controller;

use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\Image;
use Neos\Neos\Domain\Service\UserService;
use Sandstorm\MxGraph\DiagramIdentifierSearchService;

class DiagramEditorController extends ActionController
{

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var UserService
     */
    protected $userService;

    /**
     * @Flow\Inject
     * @var DiagramIdentifierSearchService
     */
    protected $diagramIdentifierSearchService;

    /**
     * @Flow\Inject
     * @var ContentRepositoryRegistry
     */
    protected $contentRepositoryRegistry;

    /**
     * @Flow\InjectConfiguration(path="drawioEmbedUrl")
     * @var string
     */
    protected $drawioEmbedUrl;
    const LOCAL_DRAWIO_EMBED_URL = 'LOCAL';

    /**
     * @Flow\InjectConfiguration(path="drawioEmbedParameters")
     * @var array
     */
    protected $drawioEmbedParameters;

    /**
     * @Flow\InjectConfiguration(path="drawioConfiguration")
     * @var array
     */
    protected $drawioConfiguration;


    /**
     * @param Node $diagramNode
     */
    public function indexAction(Node $diagramNode)
    {
        $drawioEmbedUrlWithParameters = $this->drawioEmbedUrl;
        if ($drawioEmbedUrlWithParameters === self::LOCAL_DRAWIO_EMBED_URL) {
            $drawioEmbedUrlWithParameters = $this->uriBuilder->uriFor('offlineLocalDiagramsNet');
        }
        $drawioEmbedParameters = $this->drawioEmbedParameters;
        // these parameters must be hard-coded; otherwise our application won't work
        $drawioEmbedParameters['embed'] = '1';
        $drawioEmbedParameters['configure'] = '1';
        $drawioEmbedParameters['proto'] = 'json';

        $drawioLanguage = '';
        $interfaceLanguage = $this->userService->getCurrentUser()?->getPreferences()->getInterfaceLanguage();
        if ($interfaceLanguage === 'da') {
            $drawioLanguage = 'da';
        } elseif ($interfaceLanguage === 'de') {
            $drawioLanguage = 'de';
        } elseif ($interfaceLanguage === 'es') {
            $drawioLanguage = 'es';
        } elseif ($interfaceLanguage === 'fi') {
            $drawioLanguage = 'fi';
        } elseif ($interfaceLanguage === 'fr') {
            $drawioLanguage = 'fr';
        } elseif ($interfaceLanguage === 'lv') {
            $drawioLanguage = 'lv';
        } elseif ($interfaceLanguage === 'nl') {
            $drawioLanguage = 'nl';
        } elseif ($interfaceLanguage === 'no') {
            $drawioLanguage = 'no';
        } elseif ($interfaceLanguage === 'pl') {
            $drawioLanguage = 'pl';
        } elseif ($interfaceLanguage === 'pt-BR') {
            $drawioLanguage = 'pt-br';
        } elseif ($interfaceLanguage === 'ru') {
            $drawioLanguage = 'ru';
        } elseif ($interfaceLanguage === 'zh-CN') {
            $drawioLanguage = 'zh';
        } elseif ($interfaceLanguage !== 'en') {
            // default or míssing language setting
            $this->logger->warning('Unknown interface language: ' . $interfaceLanguage);
        }

        if (!empty($drawioLanguage)) {
            $drawioEmbedParameters['lang'] = $drawioLanguage;
        }

        $drawioEmbedUrlWithParameters .= '?' .  http_build_query($drawioEmbedParameters);

        $this->view->assign('diagram', $diagramNode->getProperty('diagramSource'));
        $this->view->assign('diagramNode', NodeAddress::fromNode($diagramNode)->toJson());
        $this->view->assign('drawioEmbedUrlWithParameters', $drawioEmbedUrlWithParameters);
        $this->view->assign('drawioConfiguration', (array)$this->drawioConfiguration);

    }

    /**
     * TODO: unused?
     */
    public function offlineLocalDiagramsNetAction()
    {
    }


    /**
     * @param Node $node
     * @param string $xml
     * @param string $svg
     * @Flow\SkipCsrfProtection
     */
    public function saveAction(Node $node, $xml, $svg)
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);

        if (empty($svg)) {
            // XML without SVG -> autosaved - not supported right now.
            $contentRepository->handle(SetNodeProperties::create(
                $node->workspaceName,
                $node->aggregateId,
                $node->originDimensionSpacePoint,
                PropertyValuesToWrite::fromArray(['diagramSourceAutosaved' => $xml]),
            ));
            // TODO: If not supported, what does the code abode do?
            throw new \RuntimeException("TODO - autosave not supported right now.");
        }

        $propertyValuesToWrite = PropertyValuesToWrite::fromArray([
            'diagramSource' => $xml,
            'diagramSvgText' => $svg,
        ]);

        $diagramIdentifier = $node->getProperty('diagramIdentifier');
        if (!empty($diagramIdentifier)) {
            // update related diagrams
            foreach ($this->diagramIdentifierSearchService->findRelatedDiagramsWithIdentifierExcludingOwn($diagramIdentifier, $node) as $relatedDiagramNode) {
                $contentRepository->handle(SetNodeProperties::create(
                    $relatedDiagramNode->workspaceName,
                    $relatedDiagramNode->aggregateId,
                    $relatedDiagramNode->originDimensionSpacePoint,
                    $propertyValuesToWrite->merge(PropertyValuesToWrite::fromArray([
                        'diagramSource' => $xml,
                        'diagramSvgText' => $svg,
                    ])
                )));
            }
        }

        // BEGIN DEPRECATION since version 3.0.0
        // TODO: Because the Neos 9 compat version will be breaking we can get rid of deprecations introduced in 3.x
        $persistentResource = $this->resourceManager->importResourceFromContent($svg, 'diagram.svg');

        $image = $node->getProperty('image');
        if ($image instanceof Asset) {
            // BUG: this also changes the live workspace - nasty. But if we remove it, we get 1000s of assets
            // cluttering the Media UI.
            // TODO: What could be a fix? Remove all assets that are not live or the latest version in a workspace?
            $image->setResource($persistentResource);
        } else {
            $image = new Image($persistentResource);
        }

        $propertyValuesToWrite = $propertyValuesToWrite->withValue('image', $image);
        $contentRepository->handle(SetNodeProperties::create(
            $node->workspaceName,
            $node->aggregateId,
            $node->originDimensionSpacePoint,
            $propertyValuesToWrite,
        ));

        return 'OK';
    }
}
