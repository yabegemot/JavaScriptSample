<?php

namespace Smart\MainBundle\Report;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Query;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Smart\MainBundle\DataTable\Abstraction\AbstractProperty;
use Smart\MainBundle\Entity\Company;
use Smart\MainBundle\Entity\Report;
use Smart\MainBundle\Entity\Request as RequestEntity;
use Smart\UserBundle\Entity\User;
use Smart\MainBundle\Report\ReportBuilder;
use Smart\MainBundle\Report\Configuration\SupplierCompanyRequestsConfiguration;
use Smart\MainBundle\Report\Configuration\ReportConfigurationInterface;

class SupplierCompanyRequestsReportBuilder extends ReportBuilder {

    /**
     * Report configuration class name
     * @var string
     */

    protected $reportConfiguration;

    /**
     * Does Paging, Sorting and Filtering done on server side
     * @var boolean
     */

    protected $kendoServerSide;

	/**
	 * Serves in __construct for class initialization
	 * 
	 * @see AbstractProperty final __construct()
	 * @see AbstractProperty final __list()
	 * 
	 * @param array $args
	 * @return void
	*/
	protected function __init() {
        $this->reportConfiguration = SupplierCompanyRequestsConfiguration::class;
        $this->kendoServerSide = true;
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
    public function buildReport(ContainerInterface $container, Request $request, Report $report, $user, $companies, $options) {

        /** @var string $reportConfiguration */
        $reportConfiguration = $this->reportConfiguration;

        /** @var array $conditions */
        $conditions = $report->getConditions($this->controller->get('doctrine.orm.entity_manager'));

        if( ! $this->kendoServerSide ) {
            /** @var array $results */
            $results = $this->controller->request_repository->findForSupplierCompanyRequestsReport(
                    $user,
                    $companies,
                    $reportConfiguration::reportDbAliaces(),
                    $conditions,
                    $options);

            /** @var array $postProcessResults */
            // structure of $postProcessResults: ['records' => array, 'totals' => array, 'comments' => array, 'count' => integer]
            $postProcessResults = $reportConfiguration::recalculateRecords($results, $container, $report);

            return $postProcessResults;

        } else {
            /** @var QueryBuilder $queryBuilder */
            $queryBuilder = $this->controller->request_repository->supplierCompanyRequestsReportQueryBuilder(
                    $user,
                    $companies,
                    $reportConfiguration::reportDbAliaces(),
                    $conditions,
                    $options);

            /** @var array $data */
            // structure of $data: ['results' => array, 'totals' => array, 'count' => integer, 'requestHash => string]
            $data = $this->getData($container, $queryBuilder, $request);
            /** @var array $comments */
            $comments = array();
            $comments['comment1'] = '* Note that the total awarded is included in total bid.';
            $comments['comment2'] = '** Average number of options per request.';
            /** @var array $postProcessResults */
            // structure of $postProcessResults: ['records' => array, 'totals' => array, 'comments' => array, 'count' => integer, 'requestHash => string]
            $postProcessResults = ['records' => $reportConfiguration::processRecords($data['results'], $container, $report),
                                    'totals' => $data['totals'],
                                    'comments' => $comments,
                                    'count' => (int)$data['count']
                                ];
            if( array_key_exists('requestHash', $data) ) {
                $postProcessResults['requestHash'] = $data['requestHash'];
            }

            return $postProcessResults;
        }
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
    protected function filterData(ContainerInterface $container, QueryBuilder $queryBuilder, Request $request) {

        /** @var string $reportConfiguration */
        $reportConfiguration = $this->reportConfiguration;
        /** @var string $request_alias */
        /** @var string $company_alias */
        /** @var string $reservation_alias */
        /** @var string $option_alias */
        /** @var string $user_alias */
        /** @var string $client_alias */
        extract($reportConfiguration::reportDbAliaces(), EXTR_OVERWRITE);
        $helpers = $reportConfiguration::helpers($container);
        /** @var string $sSearch */
        $sSearch = trim($request->get('sSearch', ''));
        /** @var array $kendoFilterableFields */
        $kendoFilterableFields = $reportConfiguration::getKendoFilterableFields($this->controller->get('doctrine.orm.entity_manager'));

        if( ! empty($sSearch) && $queryBuilder instanceof QueryBuilder ) {
            /** @var array $searchFields */
            $searchFields = array();
            foreach( $kendoFilterableFields as $kendoFilterableField ) {

                switch( $kendoFilterableField ) {
                    case 'request_uid':
                        $searchFields[] = "request_uid LIKE :search";
                        $searchFields[] = "CONCAT('".RequestEntity::DISPLAY_PREFIX."', ".$request_alias.".uid) LIKE :search";
                        break;
                    case 'rate_type':
                    case 'budget_type':
                        if( array_key_exists('rate_type', $helpers) ) {
                            $rateTypes = $helpers['rate_type'];
                            foreach( $rateTypes as $value => $label ) {
                                if( strpos($label, $sSearch) !== false ) {
                                    $searchFields[] = $kendoFilterableField." = '".$value."'";
                                }
                            }
                        }
                        break;
                    default:
                        $searchFields[] = $kendoFilterableField." LIKE :search";
                        break;
                }
            }
            $queryBuilder->andHaving('('.implode(' OR ', $searchFields).')')
                        ->setParameter('search', '%'.$sSearch.'%');
        }

        return $queryBuilder;
    }

    /**
     * Update Query Builder with column sorting
     * 
     * @param ContainerInterface $container
     * @param QueryBuilder $queryBuilder
     * @param array $sorting
     * 
     * @return QueryBuilder
    */
    protected function sortData(ContainerInterface $container, QueryBuilder $queryBuilder, array $sorting=array()) {

        /** @var string $reportConfiguration */
        $reportConfiguration = $this->reportConfiguration;
        /** @var integer $orderByCount */
        $orderByCount = 0;

        foreach( $sorting as $column => $sortDirection ) {

            if( ! $reportConfiguration::hasFieldMetadata($column, $this->controller->get('doctrine.orm.entity_manager')) ) {
                continue;
            }

            $orderByCount++;
            /** @var srting $columnOrderBy */
            $columnOrderBy = $column;

            switch( $column ) {
                case 'request_uid':
                    $columnOrderBy = 'request_uid';
                    break;
                default:
                    break;
            }

            if( $orderByCount == 1 ) {
                $queryBuilder->orderBy($columnOrderBy, $sortDirection);
            } else {
                $queryBuilder->addOrderBy($columnOrderBy, $sortDirection);
            }
        }

        return $queryBuilder;
    }

    /**
     * Update Query Builder with column sorting
     * 
     * @param ContainerInterface $container
     * @param QueryBuilder $queryBuilder
     * @param Request $request
     * 
     * @return QueryBuilder
    */
    protected function getAggregates(ContainerInterface $container, QueryBuilder $queryBuilder, Request $request) {

        /** @var array $results */
        $results = $queryBuilder->getQuery()->getResult(Query::HYDRATE_ARRAY);
        /** @var integer $resultsCount */
        $resultsCount = count($results);

        array_walk($results, function(&$result) {

            $result['pending_with_bid'] = $result['pending'] === ReportConfigurationInterface::REPORT_YES
                                                                && $result['bid'] === ReportConfigurationInterface::REPORT_YES ? 1 : 0;

            $result['bid'] = $result['bid'] === ReportConfigurationInterface::REPORT_YES ? 1 : 0;
            $result['decline'] = $result['decline'] === ReportConfigurationInterface::REPORT_YES ? 1 : 0;
            $result['incomplete'] = $result['incomplete'] === ReportConfigurationInterface::REPORT_YES ? 1 : 0;
            $result['cancelled'] = $result['cancelled'] === ReportConfigurationInterface::REPORT_YES ? 1 : 0;
            $result['option_awarded'] = $result['option_awarded'] === ReportConfigurationInterface::REPORT_YES ? 1 : 0;

            $result['pending'] = $result['pending'] === ReportConfigurationInterface::REPORT_YES ? 1 : 0;
        });

        /** @var integer $sumBid */
        $sumBid = array_sum(array_column($results, 'bid'));
        /** @var integer $sumDecline */
        $sumDecline = array_sum(array_column($results, 'decline'));
        /** @var integer $sumIncomplete */
        $sumIncomplete = array_sum(array_column($results, 'incomplete'));
        /** @var integer $sumCancelled */
        $sumCancelled = array_sum(array_column($results, 'cancelled'));
        /** @var integer $sumOptionAwarded */
        $sumOptionAwarded = array_sum(array_column($results, 'option_awarded'));

        /** @var integer $optionsTotal */
        $optionsTotal = array_sum(array_column($results, 'options'));
        /** @var integer $requestsTotal */
        $requestsTotal = count(array_unique(array_column($results, 'request_uid')));

        /** @var integer $statusReviewTotal */
        $statusReviewTotal = array_sum(array_column($results, 'status_review'));
        /** @var integer $statusApprovedTotal */
        $statusApprovedTotal = array_sum(array_column($results, 'status_approved'));
        /** @var integer $statusNoAreaTotal */
        $statusNoAreaTotal = array_sum(array_column($results, 'status_no_area'));
        /** @var integer $statusCanceledTotal */
        $statusCanceledTotal = array_sum(array_column($results, 'status_canceled'));
        /** @var integer $statusClosedTotal */
        $statusClosedTotal = array_sum(array_column($results, 'status_closed'));
        /** @var integer $statusClosedTotal */
        $statusPendingTotal = array_sum(array_column($results, 'pending'));
        /** @var integer $statusPendingWithBidTotal */
        $statusPendingWithBidTotal = array_sum(array_column($results, 'pending_with_bid'));

        /** @var integer $statusCalculateTotal */
        $statusCalculateTotal = $statusReviewTotal + $statusApprovedTotal + $statusNoAreaTotal + $statusClosedTotal;

        /** @var float $bidAverage */
        $bidAverage = 0;
        /** @var float $declineAverage */
        $declineAverage = 0;
        /** @var float $incompleteAverage */
        $incompleteAverage = 0;
        /** @var float $canceledAverage */
        $canceledAverage = 0;
        /** @var float $awardedAverage */
        $awardedAverage = 0;
        if( $statusCalculateTotal > 0 ) {
            $bidAverage = round( $sumBid / $statusCalculateTotal, 4 );
            $declineAverage = round( $sumDecline / $statusCalculateTotal, 4 );
            $incompleteAverage = round( $sumIncomplete / $statusCalculateTotal, 4 );
            //$canceledAverage = round( $sumCancelled / $statusCalculateTotal, 4 );
            if ($statusCalculateTotal - $statusPendingWithBidTotal > 0) {
                $awardedAverage = round($sumOptionAwarded / ($statusCalculateTotal - $statusPendingWithBidTotal), 4);
            }
            else {
                $awardedAverage = 0;
            }
        }

        if( $resultsCount > 0 ) {
            $canceledAverage = round( $sumCancelled / $resultsCount, 4 );
        }

        /** @var float $optionsPerRequestAverage */
        $optionsPerRequestAverage = 0;
        if( $requestsTotal > 0 ) {
            $optionsPerRequestAverage = round( $optionsTotal / $requestsTotal, 2 );
        }

        $bidAverage = ($bidAverage * 100) . ' %';
        $declineAverage = ($declineAverage * 100) . ' %';
        $incompleteAverage = ($incompleteAverage * 100) . ' %';
        $canceledAverage = ($canceledAverage * 100) . ' %';
        $awardedAverage = ($awardedAverage * 100) . ' %';

        /** @var array $totals */
        // structure of $totals: [
                                //'field' => [
                                            //'aggregate' => value
                                            //]
                                //]
        $totals = array(
            'client_name' => array(
                    'total' => 'Total : ',
                    'average' => '',
                    'grand_total' => 'Grand Total : ',
                    'grand_average' => ''
                ),
            'bid' => array(
                    'total' => $sumBid,
                    'average' => $bidAverage,
                    'grand_total' => $resultsCount,
                    'grand_average' => ''
                ),
            'options' => array(
                    'total' => $optionsPerRequestAverage .'**',
                    'average' => '',
                    'grand_total' => '',
                    'grand_average' => ''
                ),
            'pending' => array(
                    'total' => '',
                    'average' => '',
                    'grand_total' => $statusPendingTotal,
                    'grand_average' => ''
                ),
            'decline' => array(
                    'total' => $sumDecline,
                    'average' => $declineAverage,
                    'grand_total' => $sumDecline,
                    'grand_average' => ''
                ),
            'incomplete' => array(
                    'total' => $sumIncomplete,
                    'average' => $incompleteAverage,
                    'grand_total' => '',
                    'grand_average' => ''
                ),
            'cancelled' => array(
                    'total' => '',
                    'average' => '',
                    'grand_total' => $sumCancelled,
                    'grand_average' => $canceledAverage
                ),
            'option_awarded' => array(
                    'total' => $sumOptionAwarded . '*',
                    'average' => $awardedAverage,
                    'grand_total' => '',
                    'grand_average' => ''
                )
        );

        return array('totals' => $totals, 'count' => $resultsCount);
    }

}
