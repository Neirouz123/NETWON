<?php

namespace App\Controller;

use App\Repository\ProcessedSiteRepository;
use App\Repository\TicketRepository;
use App\Repository\NotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\SiteAlertRepository; 

class UserDashboardController extends AbstractController
{

#[Route('/user/dashboard', name: 'user_dashboard_home')]
public function home(
    Request $request,
    ProcessedSiteRepository $processedSiteRepository,
    TicketRepository $ticketRepository,
    SiteAlertRepository $siteAlertRepository // ✅ ajout
): Response {
    $this->denyAccessUnlessGranted('ROLE_USER');

    /** @var \App\Entity\User $user */
    $user = $this->getUser();
    $userService = $user->getService();

    $classificationFilter = $request->query->get('classification');
    $criticalFilter = $request->query->get('critical');

    $totalSites = $processedSiteRepository->countAllSites($userService);

    // ✅ Sites critiques basés sur siteStatus = 'CRITIQUE'
    $criticalSites = $processedSiteRepository->countBySiteStatus('CRITIQUE', $userService);
    $criticalPercentage = $totalSites > 0 ? round(($criticalSites / $totalSites) * 100, 1) : 0;

    // ✅ Alertes réseau récentes pour le service de l'utilisateur
    $alertCounts = $siteAlertRepository->countByEtatForService($userService, 7);
    $defaults = ['CONGESTION' => 0, 'BRIDAGE' => 0, 'RISQUE_DE_CONGESTION' => 0];
    $alertCounts = array_merge($defaults, $alertCounts);
    $recentAlerts = array_sum($alertCounts);

    $classificationStats = $processedSiteRepository->getClassificationStats($userService);
    $allClassifications = array_keys($classificationStats);

    // Avancement des workflows (portée globale pour l'instant)
    $workflowStats = $ticketRepository->getWorkflowSitesProgress();

    $activeWorkflows = (int) $ticketRepository->createQueryBuilder('t')
        ->select('COUNT(t.id)')
        ->where('t.status NOT IN (:closedStatuses)')
        ->setParameter('closedStatuses', ['completed', 'closed'])
        ->getQuery()
        ->getSingleScalarResult();

    $totalWorkflows = (int) $ticketRepository->createQueryBuilder('t')->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();

    $recentProcessedSites = $ticketRepository->findRecentlyProcessedTicketSites(5);

    return $this->render('dashboard/user/home.html.twig', [
        'totalSites' => $totalSites,
        'criticalSites' => $criticalSites,
        'criticalPercentage' => $criticalPercentage,
        'recentAlerts' => $recentAlerts,                          // ✅ nouveau
        'serviceName' => $userService ?: 'SHARED',
        'classificationStats' => $classificationStats,
        'allClassifications' => $allClassifications,
        'currentClassification' => $classificationFilter,
        'currentCritical' => $criticalFilter,
        'workflowProgress' => $workflowStats['progress_percent'],
        'workflowCompletedSites' => $workflowStats['completed_sites'],
        'workflowTotalSites' => $workflowStats['total_sites'],
        'activeWorkflows' => $activeWorkflows,
        'totalWorkflows' => $totalWorkflows,
        'recentProcessedSites' => $recentProcessedSites,
    ]);
}

    #[Route('/dashboard/sites', name: 'user_dashboard_sites')]
    public function userSites(Request $request, ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $user = $this->getUser();
        $userService = $user->getService();

        $page = (int) $request->query->get('page', 1);
        $classification = $request->query->get('classification');
        $search = $request->query->get('search');
        $statusFilter = $request->query->get('status');

        $pagination = $processedSiteRepository->findSitesPaginated(
            $userService,
            $classification,
            $search,
            $page,
            50,
            $statusFilter
        );

        $classificationStats = $processedSiteRepository->getClassificationStats($userService);
        $classifications = array_keys($classificationStats);

        return $this->render('dashboard/sites.html.twig', [
            'sites' => $pagination['items'],
            'pagination' => $pagination,
            'currentClassification' => $classification,
            'currentSearch' => $search,
            'currentStatus' => $statusFilter,
            'classifications' => $classifications,
            'statusOptions' => ProcessedSiteRepository::getStatusFilterOptions(),
            'totalSites' => $pagination['total'],
            'pageTitle' => 'Mes sites (' . ($userService ?: 'Service non défini') . ')',
        ]);
    }

    #[Route('/user/import', name: 'dashboard_import')]
    public function importForm(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->render('dashboard/user/import.html.twig');
    }

    #[Route('/user/export', name: 'dashboard_export')]
    public function exportForm(ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $userService = $user->getService();

        return $this->render('dashboard/user/export.html.twig', [
            'serviceName' => $userService ?: 'SHARED',
            'siteNames' => $processedSiteRepository->findDistinctSiteNames($userService),
        ]);
    }

    #[Route('/user/kpis', name: 'dashboard_kpis')]
    public function kpis(ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $userService = $user->getService();
        return $this->render('dashboard/kpis/common.html.twig', [
            'sites' => $processedSiteRepository->findAllSitePairs($userService),
            'kpiDataRoute' => 'user_kpis_data',
            'kpiTitle' => 'KPIs - ' . ($userService ?: 'SHARED'),
            'userId' => $user->getId(),
        ]);
    }

    #[Route('/kpis/data', name: 'user_kpis_data', methods: ['GET'])]
    public function kpisData(Request $request, ProcessedSiteRepository $repo): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $prefix = $request->query->get('site');
        $start = $request->query->get('start');
        $end = $request->query->get('end');
        $days = (int) $request->query->get('days', 30);

        if (!$prefix) {
            return $this->json(['error' => 'Site parameter required'], 400);
        }

        try {
            if ($start && $end) {
                $startDate = new \DateTime($start);
                $endDate = new \DateTime($end);
                $endDate->setTime(23, 59, 59);
                $data = $repo->getKpiCurvesDataForPrefix($prefix, $startDate, $endDate);
            } else {
                $data = $repo->getKpiCurvesDataForPrefix($prefix, null, null, $days);
            }
            return $this->json($data);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Erreur interne : ' . $e->getMessage()], 500);
        }
    }

    
    #[Route('/dashboard/alerts', name: 'user_dashboard_alerts')]
public function userAlerts(
    ProcessedSiteRepository $processedSiteRepository,
    NotificationRepository $notificationRepository
): Response {
    $this->denyAccessUnlessGranted('ROLE_USER');
    /** @var \App\Entity\User $user */
    $user = $this->getUser();
    $userService = $user->getService();

    $criticalSites = $processedSiteRepository->findCriticalSites($userService, 50);
    $siteAlerts = $processedSiteRepository->findRecentSiteAlerts($userService, 100);
    $siteAlertCounts = $processedSiteRepository->getSiteAlertCounts($userService);

    $notifications = $notificationRepository->findLatestForUser($user, 100);
    $nbDelayAlerts = count(array_filter(
        $notifications,
        fn ($n) => in_array($n->getType(), ['deadline_reminder', 'ticket_overdue', 'deadline_overdue', 'deadline_yellow', 'deadline_red'], true)
    ));
    $nbCriticalSites = count($criticalSites);

    return $this->render('dashboard/user/alerts.html.twig', [  // ✅ correction du chemin
        'criticalSites' => $criticalSites,
        'siteAlerts' => $siteAlerts,
        'siteAlertCounts' => $siteAlertCounts,
        'notifications' => $notifications,
        'nbDelayAlerts' => $nbDelayAlerts,
        'nbCriticalSites' => $nbCriticalSites,
        'userService' => $userService,
    ]);
}


 #[Route('/user/export/sites', name: 'user_data_export_sites', methods: ['GET', 'POST'])]
    public function exportUserSites(Request $request, ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $service = $user->getService();

        if ($request->isMethod('POST')) {
            $mode = $request->request->get('export_mode', 'all');
            $siteNames = $request->request->all('sites');
            $siteSearch = trim((string) $request->request->get('site_search', ''));
            $dateFrom = $request->request->get('date_from');
            $dateTo = $request->request->get('date_to');

            $sites = $processedSiteRepository->findForAdvancedExport(
                $service,
                $mode,
                $siteNames,
                $siteSearch,
                $dateFrom,
                $dateTo
            );

            return $this->buildCsvResponse($sites, 'user_sites_export.csv');
        }

        return $this->render('dashboard/user/export.html.twig', [
            'serviceName' => $service ?: 'SHARED',
            'siteNames' => $processedSiteRepository->findDistinctSiteNames($service),
        ]);
    }

    /**
     * Génère un CSV à partir d'une liste de sites.
     */
    private function buildCsvResponse(array $sites, string $filename): Response
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF"); // BOM UTF-8

        // En-têtes
        fputcsv($handle, [
            'Site',
            'Site_Paire',
            'Classification',
            'Type_Trans',
            'Max_Trafic_Total',
            'Max_Trafic_TDD',
            'Max_Trafic_FDD',
            'Capacite_TDD',
            'Capacite_FDD',
            'Capacite_Totale',
            'Taux_Utilisation_Global',
            'Taux_Utilisation_TDD',
            'Taux_Utilisation_FDD',
            'Nombre_Occurrences',
            'Total_Measures',
            'Date_Max',
            'Service',
            'Critique',
            'Site_Status',
            'Latitude',
            'Longitude'
        ], ';');

        foreach ($sites as $site) {
            fputcsv($handle, [
                $site->getSiteName(),
                $site->getPairedSiteName(),
                $site->getClassification(),
                $site->getTypeTrans(),
                $site->getMaxTrafic(),
                $site->getMaxTraficTdd(),
                $site->getMaxTraficFdd(),
                $site->getCapaciteTddMbps(),
                $site->getCapaciteFddMbps(),
                $site->getCapaciteMbps(),
                $site->getTauxUtilisation(),
                $site->getTauxUtilisationTdd(),
                $site->getTauxUtilisationFdd(),
                $site->getNombreOccurrences(),
                $site->getTotalMeasures(),
                $site->getDateMax()?->format('Y-m-d H:i:s'),
                $site->getServiceName(),
                $site->isCritical() ? 'Oui' : 'Non',
                $site->getSiteStatus(),
                $site->getLatitude(),
                $site->getLongitude()
            ], ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

}