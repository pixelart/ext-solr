<?php
namespace ApacheSolrForTypo3\Solr\Middleware;

/***************************************************************
 *
 * All rights reserved
 *
 * This script is part of the TYPO3 project. The TYPO3 project is
 * free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * The GNU General Public License can be found at
 * http://www.gnu.org/copyleft/gpl.html.
 * A copy is found in the textfile GPL.txt and important notices to the license
 * from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 * This script is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use ApacheSolrForTypo3\Solr\Utility\RoutingUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageSlugCandidateProvider;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Middleware to create beautiful URLs for Solr
 *
 * How to use:
 * Inside of your extension create following file
 * Configuration/RequestMiddlewares.php
 *
 * return [
 *   'frontend' => [
 *     'apache-solr-for-typo3/solr-route-enhancer' => [
 *       'target' => \ApacheSolrForTypo3\Solr\Middleware\SolrRoutingMiddleware::class,
 *       'before' => [
 *         'typo3/cms-frontend/site',
 *       ]
 *     ]
 *   ]
 * ];
 *
 * @author Lars Tode <lars.tode@dkd.de>
 * @see https://docs.typo3.org/m/typo3/reference-coreapi/master/en-us/ApiOverview/RequestHandling/Index.html
 */
class SolrRoutingMiddleware implements MiddlewareInterface
{
    /**
     * Solr parameter key
     *
     * @var string
     */
    protected $namespace = 'tx_solr';

    /**
     * Settings from enhancer configuration
     *
     * @var array
     */
    protected $settings = [];

    /**
     * List of query parameters to ignore
     *
     * @var array
     */
    protected $ignoreQueryParameters = [];

    /**
     * Masque alle parameters with the given Solr key
     *
     * @var bool
     */
    protected $masqueParameter = true;

    /**
     * Process the request
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $site = $this->getSite($request->getUri());
        if (!($site instanceof Site)) {
            return $handler->handle($request);
        }
        [$pageUid, $pageSlug] = $this->retrievePageInformation($request->getUri()->getPath(), $site);
        if ($pageUid === 0) {
            return $handler->handle($request);
        }
        $enhancerConfiguration = $this->getEnhancerConfiguration($request, $site, $pageUid);

        if ($enhancerConfiguration === null) {
            return $handler->handle($request);
        }

        $this->configure($enhancerConfiguration);

        /*
         * Take slug path segments and argument from incoming URI
         */
        [$slug, $parameters] = $this->getSlugAndParameters(
            $request->getUri(),
            $enhancerConfiguration['routePath'],
            $pageSlug
        );

        // No parameter exists -> Skip
        if (count($parameters) === 0) {
            return $handler->handle($request);
        }

        /*
         * Map arguments against the argument configuration
         */
        $request = $this->enrichUriByPathArguments(
            $request,
            $enhancerConfiguration['_arguments'],
            $parameters
        );
        $uri = $request->getUri();

        /*
         * Replace internal URI with existing site taken from path information
         *
         * NOTE: TypoScript is not available at this point!
         */
        $uri = $uri->withPath($pageSlug);
        $request = $request->withUri($uri);

        return $handler->handle($request);
    }

    /**
     * Configures the middleware by enhancer configuration
     *
     * @param array $enhancerConfiguration
     */
    protected function configure(array $enhancerConfiguration): void
    {
        $this->settings = $enhancerConfiguration['solr'];
        $this->namespace = isset($enhancerConfiguration['extensionKey']) ?
            $enhancerConfiguration['extensionKey'] :
            $this->namespace;
    }

    /**
     * Retrieve the enhancer configuration for given site
     *
     * @param ServerRequestInterface $request
     * @param Site $site
     * @param int $pageUid
     * @return array|null
     */
    protected function getEnhancerConfiguration(ServerRequestInterface $request, Site $site, int $pageUid): ?array
    {
        $configuration = $site->getConfiguration();
        if (empty($configuration['routeEnhancers']) || !is_array($configuration['routeEnhancers'])) {
            return null;
        }

        foreach ($configuration['routeEnhancers'] as $routing => $settings) {
            if (empty($settings) || !isset($settings['type']) || $settings['type'] !== 'CombinedFacetEnhancer') {
                continue;
            }

            if (!in_array($pageUid, $settings['limitToPages'])) {
                continue;
            }

            return $settings;
        }

        return null;
    }

    /**
     * Extract the slug and all arguments from path
     *
     * @param UriInterface $uri
     * @param string $path
     * @param string $pageSlug
     * @return array
     */
    protected function getSlugAndParameters(UriInterface $uri, string $path, string $pageSlug): array
    {
        if ($uri->getPath() === $pageSlug) {
            return [
                $pageSlug,
                []
            ];
        }

        $uriElements = explode('/', $uri->getPath());
        $routeElements = explode('/', $path);
        $slugElements = [];
        $arguments = [];
        $process = true;
        do {
            if (count($uriElements) >= count($routeElements)) {
                $slugElements[] = array_shift($uriElements);
            } else {
                $process = false;
            }
        } while ($process);

        if (empty($routeElements[0])) {
            array_shift($routeElements);
        }

        // Extract the values
        for ($i = 0; $i < count($uriElements); $i++) {
            $key = substr($routeElements[$i], 1, strlen($routeElements[$i]) - 1);
            $key = substr($key, 0, strlen($key) - 1);

            $arguments[$key] = $uriElements[$i];
        }

        return [
            implode('/', $slugElements),
            $arguments
        ];
    }

    /**
     * Enrich the current query Params with data from path information
     *
     * @param ServerRequestInterface $request
     * @param array $arguments
     * @param array $parameters
     * @return ServerRequestInterface
     */
    protected function enrichUriByPathArguments(
        ServerRequestInterface $request,
        array $arguments,
        array $parameters
    ): ServerRequestInterface {
        $queryParams = $request->getQueryParams();
        foreach ($arguments as $fieldName => $queryPath) {
            // Skip if there is no parameter
            if (!isset($parameters[$fieldName])) {
                continue;
            }
            $pathElements = explode('/', $queryPath);

            if (!empty($this->namespace)) {
                array_unshift($pathElements, $this->namespace);
            }
            $queryParams = $this->processUriPathArgument(
                $queryParams,
                $fieldName,
                $parameters,
                $pathElements
            );
        }

        return $request->withQueryParams($queryParams);
    }

    /**
     * Converts path segment information into query parameters
     *
     * Example:
     * /products/household
     *
     * tx_solr:
     *      filter:
     *          - type:household
     *
     * @param array $queryParams
     * @param string $fieldName
     * @param array $parameters
     * @param array $pathElements
     * @return array
     */
    protected function processUriPathArgument(
        array $queryParams,
        string $fieldName,
        array $parameters,
        array $pathElements
    ): array {
        $queryKey = array_shift($pathElements);

        if (!isset($queryParams[$queryKey]) || $queryParams[$queryKey] === null) {
            $queryParams[$queryKey] = [];
        }

        if (strpos($queryKey, '-') !== false) {
            [$queryKey, $filterName] = explode('-', $queryKey, 2);

            // explode multiple values
            $values = explode(
                RoutingUtility::getFacetValueSeparator($this->settings),
                $parameters[$fieldName]
            );
            // @TODO: Support URL data bag
            foreach ($values as $value) {
                $queryParams[$queryKey][] = $filterName . ':' . $value;
            }
        } else {
            $queryParams[$queryKey] = $this->processUriPathArgument(
                $queryParams[$queryKey],
                $fieldName,
                $parameters,
                $pathElements
            );
        }

        return $queryParams;
    }

    /**
     * Retrieve the side
     *
     * @param UriInterface $uri
     * @return Site|null
     */
    protected function getSite(UriInterface $uri): ?Site
    {
        $sites = $this->getSiteFinder()->getAllSites();
        if (count($sites) === 1) {
            return array_values($sites)[0];
        }

        foreach ($sites as $siteKey => $site) {
            $baseUri = $this->getUriFromBase($site->getBase());
            if (!($baseUri instanceof UriInterface)) {
                continue;
            }

            if ($baseUri->getHost() !== $uri->getHost()) {
                continue;
            }

            return $site;
        }

        return null;
    }

    /**
     * Convert the base string into a URI object
     *
     * @param string $base
     * @return UriInterface|null
     */
    protected function getUriFromBase(string $base): ?UriInterface
    {
        try {
            /* @var Uri $uri */
            $uri = GeneralUtility::makeInstance(
                Uri::class,
                $base
            );

            return $uri;
        } catch (\InvalidArgumentException $argumentException) {
            return null;
        }
    }

    /**
     * @return SiteFinder|null
     */
    protected function getSiteFinder(): ?SiteFinder
    {
        return GeneralUtility::makeInstance(SiteFinder::class);
    }

    /**
     * Retrieve the page uid to filter the route enhancer
     *
     * @param string $path
     * @param Site $site
     * @return int
     */
    protected function retrievePageInformation(string $path, Site $site): array
    {
        $slugProvider = $this->getSlugCandidateProvider($site);
        $currentLanguage = $this->getCurrentLanguage($site);
        $scan = true;
        $pageUid = 0;
        $slug = '';
        do {
            $items = $slugProvider->getCandidatesForPath($path, $currentLanguage);
            if (empty($items)) {
                $scan = false;
            } elseif (empty($path)) {
                $scan = false;
            } else {
                foreach ($items as $item) {
                    if ($item['slug'] === $path) {
                        $pageUid = (int)$item['uid'];
                        $slug = (string)$item['slug'];
                        $scan = false;
                        break;
                    }
                }

                if ($scan) {
                    $elements = explode('/', $path);
                    if (empty($elements)) {
                        $scan = false;
                    } else {
                        array_pop($elements);
                        $path = implode('/', $elements);
                    }
                }
            }
        } while($scan);

        return [$pageUid, $slug];
    }

    /**
     * Returns the current language
     * @TODO Need implementation
     *
     * @param Site $site
     * @return SiteLanguage
     */
    protected function getCurrentLanguage(Site $site): SiteLanguage
    {
        return $site->getDefaultLanguage();
    }

    /**
     * @param Site $site
     * @return PageSlugCandidateProvider
     */
    protected function getSlugCandidateProvider(Site $site): PageSlugCandidateProvider
    {
        $context = GeneralUtility::makeInstance(Context::class);
        return GeneralUtility::makeInstance(
            PageSlugCandidateProvider::class,
            $context,
            $site,
            null
        );
    }
}