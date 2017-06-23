<?php

namespace Smart\MainBundle\Report;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Smart\MainBundle\Controller\SmartController;
use Smart\MainBundle\DataTable\Abstraction\AbstractProperty;
use Smart\MainBundle\Entity\Company;
use Smart\MainBundle\Entity\Report;
use Smart\UserBundle\Entity\User;
use Smart\MainBundle\Helper\CryptoHelper;

abstract class ReportBuilder extends AbstractProperty {

    /**
     * Abstract properties definition, collects the abstract properties from parent classes
     *
     * @return array
     */
    protected function abstract_properties() {
        return array_merge(
            parent::abstract_properties(),
            array(
                'protected boolean kendoServerSide',
                'protected string reportConfiguration'
            )
        );
    }

    /**
     * Set controller
     *
     * @param SmartController $controller
     * @return ReportBuilder
     */
    public function setController(SmartController $controller) {
        $this->controller = $controller;
        return $this;
    }

    /**
     * Get report configuration
     *
     * @return string
     */
    public function getConfiguration() {
        return $this->reportConfiguration;
    }

    /**
     * Build report
     *
     * @param ContainerInterface $container
     * @param Request $request
     * @param Report $report
     * @param User $user
     * @param Company[] $companies
     * @param array $options
     *
     * @return array
     */
    abstract public function buildReport(ContainerInterface $container, Request $request, Report $report, $user, $companies, $options);

    /**
     *
     * @var boolean
     */
    protected $forCSV = false;

    /**
     * @param Request $request
     *
     * @return void
     */
    public function setFroCSV($forCSV) {
        $this->forCSV = $forCSV;
    }

    /**
     *
     * @var array
     */
    protected $requestParams;

    /**
     *
     * @var array
     */
    protected $requestCacheParams;

    /**
     *
     * @var array
     */
    protected $requestCacheAggregates;

    /**
     *
     * @var integer
     */
    protected $requestCacheCount;

    /**
     * @param Request $request
     *
     * @return void
     */
    protected function parseRequestHash(Request $request) {

        $this->requestParams = array(
            'form' => $request->request->get('form', array()),
            'sSearch' => $request->request->get('sSearch', '')
        );

        $this->requestCacheParams = array('form' => array(), 'sSearch' => '');
        $this->requestCacheAggregates = array();
        $this->requestCacheCount = 0;
        /** @var string $requestHash */
        // structure of $requestHash: ['params' => array('form' => array, 'sSearch' => string), 'aggregates' => array, 'count' => integer]
        $requestHash = $request->request->get('requestHash', '');
        try {
            /** @var array $requestHashArray */
            $requestHashArray = CryptoHelper::decrypt( urldecode($requestHash) );
            if( array_key_exists('params', $requestHashArray) ) {
                $this->requestCacheParams = $requestHashArray['params'];
            }
            if( array_key_exists('aggregates', $requestHashArray) ) {
                $this->requestCacheAggregates = $requestHashArray['aggregates'];
            }
            if( array_key_exists('count', $requestHashArray) ) {
                $this->requestCacheCount = (int)$requestHashArray['count'];
            }
        } catch(\Exception $ex){}
    }

    /**
     * @param Request $request
     *
     * @return boolean
     */
    protected function rebuildAgregates(Request $request) {
        if( !isset($this->requestParams) ) {
            $this->parseRequestHash($request);
        }

        if( $this->requestCacheParams === $this->requestParams ) {
            return false;
        }

        return true;
    }

    /**
     * @param array $params
     * @param array $aggregates
     * @param integer $count
     *
     * @return array
     */
    protected function buildRequestHash($params, $aggregates, $count) {
        /** @var string $requestHash */
        // structure of $requestHash: ['params' => array('form' => array, 'sSearch' => string), 'aggregates' => array, 'count' => integer]
        $requestHash = array('params' => $params, 'aggregates' => $aggregates, 'count' => $count);
        return urlencode( CryptoHelper::encrypt($requestHash) );
    }

    /**
     * @param ContainerInterface $container
     * @param QueryBuilder $queryBuilder
     * @param Request $request
     *
     * @return array
     */
    protected function getData(ContainerInterface $container, QueryBuilder $queryBuilder, Request $request) {

        /** @var array $data */
        $data = array();

        // 1. Filter results
        $queryBuilder = $this->filterData($container, $queryBuilder, $request);

        // 2. Get aggregates
        /** @var boolean $rebuildAgregates */
        $rebuildAgregates = $this->rebuildAgregates($request);
        // structure of $aggregates: ['totals' => array, 'count' => integer]
        /** @var array $aggregates */
        if( $rebuildAgregates ) {
            $aggregates = $this->getAggregates($container, $queryBuilder, $request);
            $data["totals"] = $aggregates["totals"];
            $data["count"] = $aggregates["count"];
        } else {
            $data["totals"] = $this->requestCacheAggregates;
            $data["count"] = $this->requestCacheCount;
        }
        $data["requestHash"] = $this->buildRequestHash($this->requestParams, $data["totals"], $data["count"]);

        // 4. Sort results
        /** @var integer $iSortingCols */
        $iSortingCols = (int)$request->get('iSortingCols', 0);

        if ($iSortingCols > 0) {

            /** @var array $sorting */
            $sorting = array();

            for ($s = 0; $s < $iSortingCols; $s++) {
                /** @var string $iSortCol */
                $iSortCol = 'iSortCol_' . $s;
                /** @var string $sortColumn */
                $sortColumn = $request->get($iSortCol, '');

                if( ! empty($sortColumn) ) {
                    /** @var string $sSortDir */
                    $sSortDir = 'sSortDir_' . $s;
                    /** @var string $sortDirection */
                    $sortDirection = $request->get($sSortDir, 'asc');
                    $sorting[$sortColumn] = $sortDirection;
                }
            }

            $queryBuilder = $this->sortData($container, $queryBuilder, $sorting);
        }

        // 5. Subset results
        /** @var integer $offset */
        $offset = (int)$request->get('iDisplayStart', 0);
        $queryBuilder->setFirstResult($offset);

        /** @var integer $length */
        $length = (int)$request->get('iDisplayLength', -1);
        if( $length !== -1 ) {
            $queryBuilder->setMaxResults($length);
        }

        // 6. Get results
        /** @var Query $query */
        $query = $queryBuilder->getQuery();
        $data['results'] = $query->getResult(Query::HYDRATE_ARRAY);

        return $data;
    }

    /**
     * Update Query Builder with search filter
     * 
     * @param ContainerInterface $container
     * @param QueryBuilder $queryBuilder
     * @param Request $request
     * 
     * @return QueryBuilder
    */
    abstract protected function filterData(ContainerInterface $container, QueryBuilder $queryBuilder, Request $request);

    /**
     * Update Query Builder with column sorting
     * 
     * @param ContainerInterface $container
     * @param QueryBuilder $queryBuilder
     * @param array $sorting
     * 
     * @return QueryBuilder
    */
    abstract protected function sortData(ContainerInterface $container, QueryBuilder $queryBuilder, array $sorting=array());

    /**
     * Update Query Builder with column sorting
     * 
     * @param ContainerInterface $container
     * @param QueryBuilder $queryBuilder
     * @param Request $request
     * 
     * @return QueryBuilder
    */
    abstract protected function getAggregates(ContainerInterface $container, QueryBuilder $queryBuilder, Request $request);
}