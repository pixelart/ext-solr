<?php
namespace ApacheSolrForTypo3\Solr\System\Solr;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2009-2015 Ingo Renner <ingo@typo3.org>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use ApacheSolrForTypo3\Solr\System\Configuration\TypoScriptConfiguration;
use ApacheSolrForTypo3\Solr\System\Logging\SolrLogManager;
use ApacheSolrForTypo3\Solr\System\Solr\Parser\SchemaParser;
use ApacheSolrForTypo3\Solr\System\Solr\Parser\StopWordParser;
use ApacheSolrForTypo3\Solr\System\Solr\Parser\SynonymParser;
use ApacheSolrForTypo3\Solr\System\Solr\Service\SolrAdminService;
use ApacheSolrForTypo3\Solr\System\Solr\Service\SolrReadService;
use ApacheSolrForTypo3\Solr\System\Solr\Service\SolrWriteService;
use ApacheSolrForTypo3\Solr\System\Util\SiteUtility;
use ApacheSolrForTypo3\Solr\Util;
use GuzzleHttp\Client as GuzzleClient;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Solarium\Client;
use Solarium\Core\Client\Adapter\AdapterInterface;
use Solarium\Core\Client\Adapter\Curl;
use Solarium\Core\Client\Adapter\Psr18Adapter;
use Solarium\Core\Client\Adapter\TimeoutAwareInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Solr Service Access
 *
 * @author Ingo Renner <ingo@typo3.org>
 */
class SolrConnection
{
    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var SolrAdminService
     */
    protected $adminService;

    /**
     * @var SolrReadService
     */
    protected $readService;

    /**
     * @var SolrWriteService
     */
    protected $writeService;

    /**
     * @var TypoScriptConfiguration
     */
    protected $configuration;

    /**
     * @var SynonymParser
     */
    protected $synonymParser = null;

    /**
     * @var StopWordParser
     */
    protected $stopWordParser = null;

    /**
     * @var SchemaParser
     */
    protected $schemaParser = null;

    /**
     * @var Node[]
     */
    protected $nodes = [];

    /**
     * @var SolrLogManager
     */
    protected $logger = null;

    /**
     * Adapter class used for the communication
     *
     * @var string
     */
    protected $adapterClass = Curl::class;

    /**
     * This property should be removed at the point Guzzle version 7 is supported by TYPO3
     *
     * @var bool|null
     * @deprecated Will be removed with a future TYPO3 version
     */
    protected $psrCompatibilityCheckCache = null;

    /**
     * @var Client[]
     */
    protected $clients = [];

    /**
     * Constructor
     *
     * @param Node $readNode,
     * @param Node $writeNode
     * @param ?TypoScriptConfiguration $configuration
     * @param ?SynonymParser $synonymParser
     * @param ?StopWordParser $stopWordParser
     * @param ?SchemaParser $schemaParser
     * @param ?SolrLogManager $logManager
     * @param ?EventDispatcherInterface $eventDispatcher
     */
    public function __construct(
        Node $readNode,
        Node $writeNode,
        TypoScriptConfiguration $configuration = null,
        SynonymParser $synonymParser = null,
        StopWordParser $stopWordParser = null,
        SchemaParser $schemaParser = null,
        SolrLogManager $logManager = null,
        EventDispatcherInterface $eventDispatcher = null
    ) {
        $this->nodes['read'] = $readNode;
        $this->nodes['write'] = $writeNode;
        $this->nodes['admin'] = $writeNode;
        $this->configuration = $configuration ?? Util::getSolrConfiguration();
        $this->synonymParser = $synonymParser;
        $this->stopWordParser = $stopWordParser;
        $this->schemaParser = $schemaParser;
        $this->logger = $logManager;
        $this->eventDispatcher = $eventDispatcher ?? GeneralUtility::getContainer()->get(EventDispatcherInterface::class);
    }

    /**
     * @param string $key
     * @return Node
     */
    public function getNode($key)
    {
        return $this->nodes[$key];
    }

    /**
     * @return SolrAdminService
     */
    public function getAdminService()
    {
        if ($this->adminService === null) {
            $this->adminService = $this->buildAdminService();
        }

        return $this->adminService;
    }

    /**
     * @return SolrAdminService
     */
    protected function buildAdminService()
    {
        $endpointKey = 'admin';
        $client = $this->getClient($endpointKey);
        $this->initializeClient($client, $endpointKey);
        return GeneralUtility::makeInstance(SolrAdminService::class, $client, $this->configuration, $this->logger, $this->synonymParser, $this->stopWordParser, $this->schemaParser);
    }

    /**
     * @return SolrReadService
     */
    public function getReadService()
    {
        if ($this->readService === null) {
            $this->readService = $this->buildReadService();
        }

        return $this->readService;
    }

    /**
     * @return SolrReadService
     */
    protected function buildReadService()
    {
        $endpointKey = 'read';
        $client = $this->getClient($endpointKey);
        $this->initializeClient($client, $endpointKey);
        return GeneralUtility::makeInstance(SolrReadService::class, $client);
    }

    /**
     * @return SolrWriteService
     */
    public function getWriteService()
    {
        if ($this->writeService === null) {
            $this->writeService = $this->buildWriteService();
        }

        return $this->writeService;
    }

    /**
     * @return SolrWriteService
     */
    protected function buildWriteService()
    {
        $endpointKey = 'write';
        $client = $this->getClient($endpointKey);
        $this->initializeClient($client, $endpointKey);
        return GeneralUtility::makeInstance(SolrWriteService::class, $client);
    }

    /**
     * @param Client $client
     * @param string $endpointKey
     * @return Client
     */
    protected function initializeClient(Client $client, $endpointKey) {
        if (trim($this->getNode($endpointKey)->getUsername()) === '') {
            return $client;
        }

        $username = $this->getNode($endpointKey)->getUsername();
        $password = $this->getNode($endpointKey)->getPassword();
        $this->setAuthenticationOnAllEndpoints($client, $username, $password);

        return $client;
    }

    /**
     * @param Client $client
     * @param string $username
     * @param string $password
     */
    protected function setAuthenticationOnAllEndpoints(Client $client, $username, $password)
    {
        foreach ($client->getEndpoints() as $endpoint) {
            $endpoint->setAuthentication($username, $password);
        }
    }

    /**
     * @param string $endpointKey
     * @return Client
     */
    protected function getClient(string $endpointKey): Client
    {
        if ($this->clients[$endpointKey]) {
            return $this->clients[$endpointKey];
        }
        // TODO: Should it be a clone of the endpoint? In row 277 the key of the endpoint is set.
        $endPoint = $this->getNode($endpointKey);
        print_r($endPoint);
        $newEndpointOptions = $endPoint->getSolariumClientOptions();
        $adapter = $this->getClientAdapter($newEndpointOptions, $endpointKey);

        $client = new Client(
            $adapter,
            $this->eventDispatcher
        );
        $client->getPlugin('postbigrequest');
        $client->clearEndpoints();

        $endPoint->setKey($endpointKey);
        $client->addEndpoint($endPoint);

        $this->clients[$endpointKey] = $client;
        return $client;
    }

    /**
     * @param Client $client
     * @param string $endpointKey
     */
    public function setClient(Client $client, $endpointKey = 'read')
    {
        $this->clients[$endpointKey] = $client;
    }

    /**
     * Setup the adapter configuration for the client.
     * Consider Guzzle settings from global configuration
     *
     * @see \TYPO3\CMS\Core\Http\Client\GuzzleClientFactory::getClient
     *
     * @param array $configuration
     * @param string $endpointKey
     * @return AdapterInterface
     */
    protected function getClientAdapter(array $configuration, string $endpointKey = 'read'): AdapterInterface
    {
        $options = $configuration;
        // TODO: Replace with unified configuration
        if (false && !empty($GLOBALS['TYPO3_CONF_VARS']['HTTP'])) {
            $httpOptions = $GLOBALS['TYPO3_CONF_VARS']['HTTP'];
            $httpOptions['verify'] = filter_var(
                    $httpOptions['verify'],
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE) ?? $httpOptions['verify'];
            unset($httpOptions['timeout']);
            $options = array_merge_recursive($options, $httpOptions);
        }

        $adapterClass = $this->getVerifiedAdapterClassName($this->adapterClass, $endpointKey);

        /*
         * Psr18Adapter constructor requires more parameters than the other adapter.
         */
        if ($adapterClass === Psr18Adapter::class) {
            /* @var GuzzleClient $client */
            $client = GeneralUtility::makeInstance(GuzzleClient::class, $options);

            /* @var RequestFactory $requestFactory */
            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class, $options);

            /* @var StreamFactory $streamFactory */
            $streamFactory = GeneralUtility::makeInstance(StreamFactory::class);

            /* @var Psr18Adapter $adapter */
            $adapter = new $adapterClass(
                $client,
                $requestFactory,
                $streamFactory
            );

        } else {
            /* @var AdapterInterface $adapter */
            $adapter = new $adapterClass($options);
        }

        if ($adapter instanceof TimeoutAwareInterface) {
            $adapter->setTimeout((int)$options['timeout']);
        }

        return $adapter;
    }

    /**
     * Determine the correct adapter class
     *
     * @param string $adapterClass
     * @param string $endpointKey
     * @return string
     */
    protected function getVerifiedAdapterClassName(string $adapterClass, string $endpointKey = 'read'): string
    {
        if ($adapterClass === Psr18Adapter::class) {
            if (!$this->isGuzzleIsPsr18Compatible()) {
                $adapterClass = null;
            }
        }

        if ($adapterClass !== null) {
            $interfaces = class_implements($adapterClass);
            if (in_array(AdapterInterface::class, $interfaces)) {
                return $adapterClass;
            }
        }

        return Curl::class;
    }

    /**
     * Method to check if Guzzle is PSR-18 compatible.
     * This method is deprecated and remove at the point TYPO3 uses Guzzle v7 which implements the interface.
     *
     * @return bool
     * @deprecated Will be removed with a future TYPO3 version
     * @api
     */
    protected function isGuzzleIsPsr18Compatible(): bool
    {
        if ($this->psrCompatibilityCheckCache === null) {
            $interfaces = class_implements(GuzzleClient::class);
            $this->psrCompatibilityCheckCache = in_array(ClientInterface::class, $interfaces);
        }

        return $this->psrCompatibilityCheckCache;
    }
}
