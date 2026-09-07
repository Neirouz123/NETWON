<?php
// src/Controller/SuperuserDashboardController.php
namespace App\Controller;

use App\Entity\ProcessedSite;
use App\Repository\ProcessedSiteRepository;
use App\Repository\TicketRepository;
use App\Repository\NotificationRepository;
use App\Repository\SiteAlertRepository;
use App\Service\IaRecommendationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SuperuserDashboardController extends AbstractController
{
    #[Route('/superuser/dashboard', name: 'superuser_dashboard_home')]
    public function superuserDashboard(
        Request $request,
        ProcessedSiteRepository $processedSiteRepository,
        TicketRepository $ticketRepository,
        SiteAlertRepository $siteAlertRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $serviceFilter = $request->query->get('service');
        $classificationFilter = $request->query->get('classification');
        $criticalFilter = $request->query->get('critical');

        $totalSites = $processedSiteRepository->countAllSites($serviceFilter);
        $criticalSites = $processedSiteRepository->countBySiteStatus('CRITIQUE', $serviceFilter);
        $criticalPercentage = $totalSites > 0 ? round(($criticalSites / $totalSites) * 100, 1) : 0;

        $alertCounts = $siteAlertRepository->countByEtat(7);
        $defaults = ['CONGESTION' => 0, 'BRIDAGE' => 0, 'RISQUE_DE_CONGESTION' => 0];
        $alertCounts = array_merge($defaults, $alertCounts);
        $recentAlerts = array_sum($alertCounts);

        $serviceDistribution = $processedSiteRepository->getServiceDistribution();
        $classificationStats = $processedSiteRepository->getClassificationStats($serviceFilter);

        $allServices = array_keys($serviceDistribution);
        $allClassifications = array_keys($processedSiteRepository->getClassificationStats(null));

        $workflowStats = $ticketRepository->getWorkflowSitesProgress();

        $activeWorkflows = (int) $ticketRepository->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.status NOT IN (:closedStatuses)')
            ->setParameter('closedStatuses', ['completed', 'closed'])
            ->getQuery()
            ->getSingleScalarResult();

        $totalWorkflows = (int) $ticketRepository->createQueryBuilder('t')->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
        $recentProcessedSites = $ticketRepository->findRecentlyProcessedTicketSites(5);

        return $this->render('dashboard/superuser/home.html.twig', [
            'totalSites' => $totalSites,
            'criticalSites' => $criticalSites,
            'criticalPercentage' => $criticalPercentage,
            'recentAlerts' => $recentAlerts,
            'serviceDistribution' => $serviceDistribution,
            'classificationStats' => $classificationStats,
            'allServices' => $allServices,
            'allClassifications' => $allClassifications,
            'currentService' => $serviceFilter,
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

    #[Route('/superuser/plan-data', name: 'superuser_plan_data')]
    public function planData(
        Request $request,
        ProcessedSiteRepository $processedSiteRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $service = $request->query->get('service');
        $classification = $request->query->get('classification');
        $search = trim((string) $request->query->get('search', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $top10 = $request->query->has('top10');
        $needsUpdate = $request->query->has('needsUpdate');

        $allSites = $processedSiteRepository->findAllSitesOrderedByStatus(
            $service ?: null,
            $classification ?: null,
            $search
        );

        if ($needsUpdate) {
            $allSites = array_values(array_filter($allSites, function (ProcessedSite $s) {
                $needType = !$s->getTypeTrans() || $s->needsCapacityOrTypeUpdate();
                $needCapacity = ($s->getCapaciteTddMbps() === null && $s->getCapaciteFddMbps() === null)
                    || $s->getCapaciteMbps() === null || $s->getCapaciteMbps() <= 0;
                return $needType || $needCapacity;
            }));
            $page = 1;
        }

        if ($top10) {
            usort($allSites, function ($a, $b) {
                $occA = $a->getNombreOccurrences() ?? 0;
                $occB = $b->getNombreOccurrences() ?? 0;
                if ($occB !== $occA) return $occB - $occA;
                return ($b->getTauxUtilisation() ?? 0) <=> ($a->getTauxUtilisation() ?? 0);
            });
            $allSites = array_slice($allSites, 0, 10);
            $page = 1;
        }

        $criticalSites = 0;
        $warningSites = 0;
        $secureSites = 0;
        $sansTypeSites = 0;
        $congestionSites = 0;
        $bridageSites = 0;
        $s1DownSites = 0;

        foreach ($allSites as $site) {
            // ✅ Normalisation à la lecture pour compter correctement
            // même les anciennes lignes ('critical' -> CRITIQUE, etc.)
            $status = strtoupper((string) ($site->getStatus() ?? 'OK'));
            $status = match ($status) {
                'CRITICAL' => 'CRITIQUE',
                'SURVEILLANCE', 'WARNING' => 'SOUS_OBSERVATION',
                'SECURISE', 'SECURE' => 'OK',
                default => $status,
            };
            $etat = strtoupper((string) ($site->getSiteStatus() ?? 'OK'));
            $etat = $etat === 'CONGESTIONNE' ? 'CONGESTION' : $etat;

            if ($status === 'CRITIQUE') {
                $criticalSites++;
            } elseif ($status === 'SOUS_OBSERVATION') {
                $warningSites++;
            } elseif ($status === 'OK') {
                $secureSites++;
            }

            if ($site->getS1FailDuration() !== null && $site->getS1FailDuration() > 0) {
                $s1DownSites++;
            }

            $typeTrans = strtoupper(trim((string) $site->getTypeTrans()));
            if ($typeTrans === '' || in_array($typeTrans, ['NON_DEFINI', 'UNKNOWN', 'N/A', 'NA', '-'], true)) {
                $sansTypeSites++;
            }

            if (str_contains($etat, 'CONGESTION')) {
                $congestionSites++;
            } elseif ($etat === 'BRIDAGE') {
                $bridageSites++;
            }
        }

        $totalSites = count($allSites);

        $sitesPerPage = 20;
        $totalPages = (!$top10 && !$needsUpdate && $totalSites > 0) ? (int) ceil($totalSites / $sitesPerPage) : 1;
        if (!$top10 && !$needsUpdate) {
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $sitesPerPage;
            $sites = array_slice($allSites, $offset, $sitesPerPage);
        } else {
            $sites = $allSites;
            $totalPages = 1;
        }

        $services = array_keys($processedSiteRepository->getServiceDistribution());
        $classifications = array_keys($processedSiteRepository->getClassificationStats($service));

        $imported = $request->query->has('imported');

        return $this->render('dashboard/superuser/plan_data.html.twig', [
            'sites' => $sites,
            'allSites' => $allSites,
            'criticalSites' => $criticalSites,
            'warningSites' => $warningSites,
            'secureSites' => $secureSites,
            'sansTypeSites' => $sansTypeSites,
            'congestionSites' => $congestionSites,
            'bridageSites' => $bridageSites,
            'totalSites' => $totalSites,
            'services' => $services,
            'classifications' => $classifications,
            'currentService' => $service,
            'currentClassification' => $classification,
            'currentSearch' => $search,
            'pagination' => ['page' => $page, 'totalPages' => $totalPages, 'total' => $totalSites],
            'imported' => $imported,
            'importNeeded' => $totalSites === 0,
            'isTop10' => $top10,
            'isNeedsUpdate' => $needsUpdate,
            's1DownSites' => $s1DownSites,
        ]);
    }

    #[Route('/superuser/ia-recommendations', name: 'superuser_ia_recommendations', methods: ['GET', 'POST'])]
    public function iaRecommendations(
        Request $request,
        ProcessedSiteRepository $processedSiteRepository,
        IaRecommendationService $iaService,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $service = $request->query->get('service');
        $classification = $request->query->get('classification');
        $search = trim((string) $request->query->get('search', ''));
        $filter = $request->query->get('filter', 'all');

        $allSites = $processedSiteRepository->findAllSitesOrderedByStatus(
            $service ?: null,
            $classification ?: null,
            $search
        );

        $targetSites = array_filter($allSites, function (ProcessedSite $site) {
            $status = strtoupper((string) ($site->getSiteStatus() ?? 'NON_EVALUE'));
            $status = $status === 'CRITICAL' ? 'CRITIQUE' : $status;
            return in_array($status, ['CRITIQUE', 'SURVEILLANCE'], true);
        });

        $recommendations = $iaService->analyzeSites($targetSites);

        $prefixes = array_map(fn($s) => $s->getSiteName(), $targetSites);
        $batchTraffic = $processedSiteRepository->getTrafficHistoryForPrefixes($prefixes, 30);

        foreach ($recommendations as &$rec) {
            $siteName = $rec['siteName'];
            $data = $batchTraffic[$siteName] ?? ['labels' => [], 'values' => []];

            $rec['currentTrafficData'] = $data;

            $tauxActuel = $rec['tauxGlobal'];
            $targetUtil = 65.0;
            $ratio = ($tauxActuel > $targetUtil && $tauxActuel > 0) ? ($targetUtil / $tauxActuel) : 1.0;
            $afterValues = array_map(fn($v) => round($v * $ratio, 2), $data['values']);

            $rec['afterActionData'] = ['labels' => $data['labels'], 'values' => $afterValues];
            $rec['hasTrafficData'] = !empty($data['values']);

            $rec['graphAnalysis'] = $rec['hasTrafficData']
                ? $iaService->confirmSeverityFromGraph($data['values'], $rec['severity'])
                : ['confirmed' => null, 'trend' => 'insuffisant', 'variation' => 0, 'label' => 'ℹ️ Aucun historique de trafic disponible pour ce site'];
        }
        unset($rec);

        if ($filter === 'top10') {
            usort($recommendations, function ($a, $b) {
                if ($b['nombreOccurrences'] !== $a['nombreOccurrences']) {
                    return $b['nombreOccurrences'] <=> $a['nombreOccurrences'];
                }
                return $b['tauxGlobal'] <=> $a['tauxGlobal'];
            });
            $recommendations = array_slice($recommendations, 0, 10);
        }

        $globalStats = $iaService->generateGlobalActionPlan($recommendations);
        $allActionTypes = $iaService->getAllActionTypes();

        if ($request->isMethod('POST')) {
            $selectedSiteIds = $request->request->all('selected_sites');

            if (empty($selectedSiteIds)) {
                $this->addFlash('warning', 'Aucun site sélectionné.');
                return $this->redirectToRoute('superuser_ia_recommendations');
            }

            $deadline = $request->request->get('deadline');
            if (!$deadline) {
                $this->addFlash('error', 'La date limite est obligatoire pour créer le workflow.');
                return $this->redirectToRoute('superuser_ia_recommendations');
            }

            try {
                $deadlineDate = new \DateTime($deadline);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Date limite invalide.');
                return $this->redirectToRoute('superuser_ia_recommendations');
            }

            $priority = $request->request->get('workflow_priority', 'medium');
            $workflowName = trim((string) $request->request->get('workflow_name', ''));

            $actionsInput = $request->request->all('actions');
            $commentsInput = $request->request->all('comments');

            $validatedActions = [];
            foreach ($selectedSiteIds as $siteId) {
                $validatedActions[] = [
                    'siteId' => $siteId,
                    'actionType' => $actionsInput[$siteId]['action_type'] ?? 'MONITORING',
                    'comment' => $commentsInput[$siteId] ?? null,
                ];
            }

            $workflow = $iaService->createWorkflowFromRecommendations(
                $validatedActions,
                $this->getUser(),
                $deadlineDate,
                $priority,
                $workflowName !== '' ? $workflowName : null
            );

            $this->addFlash('success', sprintf('Workflow #%d créé pour %d site(s).', $workflow->getId(), count($validatedActions)));
            return $this->redirectToRoute('superuser_workflow_show', ['id' => $workflow->getId()]);
        }

        return $this->render('dashboard/superuser/ia_recommendations.html.twig', [
            'recommendations' => $recommendations,
            'globalStats' => $globalStats,
            'allActionTypes' => $allActionTypes,
            'services' => array_keys($processedSiteRepository->getServiceDistribution()),
            'classifications' => array_keys($processedSiteRepository->getClassificationStats($service)),
            'currentService' => $service,
            'currentClassification' => $classification,
            'currentSearch' => $search,
            'currentFilter' => $filter,
        ]);
    }

    #[Route('/superuser/sites', name: 'superuser_dashboard_sites')]
    public function superuserSites(Request $request, ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $page = (int) $request->query->get('page', 1);
        $service = $request->query->get('service');
        $classification = $request->query->get('classification');
        $search = $request->query->get('search');
        $statusFilter = $request->query->get('status');

        $pagination = $processedSiteRepository->findSitesPaginated(
            $service,
            $classification,
            $search,
            $page,
            50,
            $statusFilter
        );

        $classificationStats = $processedSiteRepository->getClassificationStats($service);
        $classifications = array_keys($classificationStats);
        $serviceDistribution = $processedSiteRepository->getServiceDistribution();
        $services = array_keys($serviceDistribution);

        return $this->render('dashboard/superuser/sites.html.twig', [
            'sites' => $pagination['items'],
            'pagination' => $pagination,
            'currentService' => $service,
            'currentClassification' => $classification,
            'currentSearch' => $search,
            'currentStatus' => $statusFilter,
            'classifications' => $classifications,
            'services' => $services,
            'statusOptions' => ProcessedSiteRepository::getStatusFilterOptions(),
            'totalSites' => $pagination['total'],
            'pageTitle' => 'Sites - Vue globale',
        ]);
    }

    #[Route('/superuser/import', name: 'superuser_dashboard_import', methods: ['GET', 'POST'])]
    public function import(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        return $this->render('dashboard/superuser/import.html.twig');
    }

    #[Route('/superuser/export', name: 'superuser_dashboard_export', methods: ['GET'])]
    public function exportForm(ProcessedSiteRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $periods = $repo->getAvailableImportWeeks();
        $allColumns = $this->getAvailableColumns();
        $defaultColumns = $this->getPlanDataColumnsOrder();

        return $this->render('dashboard/superuser/export.html.twig', [
            'services' => array_keys($repo->getServiceDistribution()),
            'siteNames' => $repo->findDistinctSiteNames(),
            'periods' => $periods,
            'allColumns' => $allColumns,
            'defaultColumns' => $defaultColumns,
        ]);
    }

    #[Route('/superuser/kpis', name: 'superuser_dashboard_kpis')]
    public function kpis(ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $user = $this->getUser();
        return $this->render('dashboard/kpis/common.html.twig', [
            'sites' => $processedSiteRepository->findAllSitePairs(),
            'kpiDataRoute' => 'superuser_kpis_data',
            'kpiTitle' => 'KPIs',
            'userIdentifier' => $user->getUserIdentifier(),
        ]);
    }

    #[Route('/superuser/kpis/data', name: 'superuser_kpis_data', methods: ['GET'])]
    public function kpisData(Request $request, ProcessedSiteRepository $repo): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
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

    #[Route('/superuser/alerts', name: 'superuser_dashboard_alerts')]
    public function alerts(
        ProcessedSiteRepository $processedSiteRepository,
        NotificationRepository $notificationRepository,
        SiteAlertRepository $siteAlertRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $siteAlerts = $siteAlertRepository->findRecentAlerts(7);

        $alertNotifications = [];
        foreach ($siteAlerts as $alert) {
            $notifs = $notificationRepository->findBy(['alert' => $alert]);
            $alertNotifications[$alert->getId()] = $notifs;
        }

        $siteAlertCounts = $siteAlertRepository->countByEtat(7);
        $defaults = ['CONGESTION' => 0, 'BRIDAGE' => 0, 'RISQUE_DE_CONGESTION' => 0];
        $siteAlertCounts = array_merge($defaults, $siteAlertCounts);

        $notifications = $notificationRepository->createQueryBuilder('n')
            ->where('n.type IN (:types)')
            ->setParameter('types', ['deadline_reminder', 'ticket_overdue', 'deadline_overdue', 'deadline_yellow', 'deadline_red'])
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        $nbDelayAlerts = $notificationRepository->createQueryBuilder('n')
            ->select('COUNT(DISTINCT n.ticket)')
            ->where('n.type IN (:types)')
            ->setParameter('types', ['deadline_reminder', 'ticket_overdue', 'deadline_overdue', 'deadline_yellow', 'deadline_red'])
            ->getQuery()
            ->getSingleScalarResult();

        return $this->render('dashboard/superuser/alerts.html.twig', [
            'siteAlerts' => $siteAlerts,
            'alertNotifications' => $alertNotifications,
            'siteAlertCounts' => $siteAlertCounts,
            'notifications' => $notifications,
            'nbDelayAlerts' => $nbDelayAlerts,
        ]);
    }

    #[Route('/superuser/fh-workflows', name: 'superuser_fh_workflows')]
    public function fhWorkflows(TicketRepository $ticketRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $tickets = $ticketRepository->findBy(['workflowType' => 'FH']);
        return $this->render('dashboard/superuser/fh_workflows.html.twig', ['tickets' => $tickets]);
    }

    /**
     * ✅ Ordre + jeu de colonnes EXACTEMENT identique à celui affiché dans
     * l'interface "Sites" et "Plan Data" (demande utilisateur point c/g) :
     * quel que soit l'export utilisé (Plan Data, Sites, export avancé),
     * on exporte toujours ce même tableau.
     */
    private function getPlanDataColumnsOrder(): array
    {
        return [
            'site',
            'classification',
            'typeTrans',
            'maxTraficTdd',
            'maxTraficFdd',
            'maxTrafic',
            'capaciteTdd',
            'capaciteFdd',
            'tauxUtilisation',
            'tauxUtilisationTdd',
            'tauxUtilisationFdd',
            'nombreOccurrences',
            'nombreOccurrencesTdd',
            'nombreOccurrencesFdd',
            'status',
            'siteStatus',
            'dropCongTdd',
            'dropCongFdd',
            'dropCongTf',
            'longitude',
            'latitude',
            's1FailDuration',
            's1FailDate',
            'capaciteUpdatedAt',
            'lastActionPerformed',
        ];
    }

    private function getAvailableColumns(): array
    {
        return [
            'site' => ['label' => 'Site', 'getter' => 'getSiteName'],
            'classification' => ['label' => 'Classification', 'getter' => 'getClassification'],
            'typeTrans' => ['label' => 'Type Trans', 'getter' => 'getTypeTrans'],
            'maxTraficTdd' => ['label' => 'Max TDD (Mbps)', 'getter' => 'getMaxTraficTdd'],
            'maxTraficFdd' => ['label' => 'Max FDD (Mbps)', 'getter' => 'getMaxTraficFdd'],
            'maxTrafic' => ['label' => 'Trafic Max (Mbps)', 'getter' => 'getMaxTrafic'],
            'capaciteTdd' => ['label' => 'Capacité TDD (Mbps)', 'getter' => 'getCapaciteTddMbps'],
            'capaciteFdd' => ['label' => 'Capacité FDD (Mbps)', 'getter' => 'getCapaciteFddMbps'],
            'tauxUtilisation' => ['label' => 'Taux Utilisation Global (%)', 'getter' => 'getTauxUtilisation'],
            'tauxUtilisationTdd' => ['label' => 'Taux Utilisation TDD (%)', 'getter' => 'getTauxUtilisationTdd'],
            'tauxUtilisationFdd' => ['label' => 'Taux Utilisation FDD (%)', 'getter' => 'getTauxUtilisationFdd'],
            'nombreOccurrences' => ['label' => 'Occurrences', 'getter' => 'getNombreOccurrences'],
            'nombreOccurrencesTdd' => ['label' => 'Occurrence TDD', 'getter' => 'getNombreOccurrencesTdd'],
            'nombreOccurrencesFdd' => ['label' => 'Occurrence FDD', 'getter' => 'getNombreOccurrencesFdd'],
            'status' => ['label' => 'Statut (status)', 'getter' => 'getStatus'],
            'siteStatus' => ['label' => 'État (siteStatus)', 'getter' => 'getSiteStatus'],
            'dropCongTdd' => ['label' => 'DropCong TDD', 'getter' => 'getDropCongTdd'],
            'dropCongFdd' => ['label' => 'DropCong FDD', 'getter' => 'getDropCongFdd'],
            'dropCongTf' => ['label' => 'DropCong TF', 'getter' => 'getDropCongTf'],
            'longitude' => ['label' => 'Longitude', 'getter' => 'getLongitude'],
            'latitude' => ['label' => 'Latitude', 'getter' => 'getLatitude'],
            's1FailDuration' => ['label' => 'S1 Fail (s)', 'getter' => 'getS1FailDuration'],
            's1FailDate' => ['label' => 'Date coupure S1', 'getter' => 'getS1FailDate'],
            'capaciteUpdatedAt' => ['label' => 'MAJ Capacité', 'getter' => 'getCapaciteUpdatedAt'],
            'lastActionPerformed' => ['label' => 'Dernière action', 'getter' => 'getLastActionPerformed'],
        ];
    }

    /**
     * ✅ Normalise 'status'/'siteStatus' vers le vocabulaire canonique
     * pour l'affichage/export, même pour les lignes historiques.
     */
    private function normalizeExportValue(string $key, $value)
    {
        if ($value === null) {
            return null;
        }
        if ($key === 'siteStatus') {
            $v = strtoupper(trim((string) $value));
            return match ($v) {
                'CONGESTION', 'CONGESTIONNE', 'CONGESTION(FDD)', 'CONGESTION(TDD)' => 'CONGESTION',
                'BRIDAGE' => 'BRIDAGE',
                'RISQUE_DE_CONGESTION' => 'RISQUE_DE_CONGESTION',
                'RISQUE_DE_BRIDAGE' => 'RISQUE_DE_BRIDAGE',
                '' => 'OK',
                default => in_array($v, ['SURVEILLANCE', 'SOUS_OBSERVATION'], true) ? 'RISQUE_DE_CONGESTION' : $v,
            };
        }
        if ($key === 'status') {
            $v = strtoupper(trim((string) $value));
            return match ($v) {
                'CRITIQUE', 'CRITICAL' => 'CRITIQUE',
                'SOUS_OBSERVATION', 'SURVEILLANCE', 'WARNING' => 'SOUS_OBSERVATION',
                'OK', 'SECURISE', 'SECURE', '' => 'OK',
                default => $v,
            };
        }
        return $value;
    }

    #[Route('/superuser/plan-data/export', name: 'superuser_export_plan_data')]
    public function exportPlanData(Request $request, ProcessedSiteRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $service = $request->query->get('service');
        $classification = $request->query->get('classification');
        $search = $request->query->get('search');

        $sites = $repo->findAllSitesOrderedByStatus($service, $classification, $search);

        return $this->buildCsvResponse($sites, $this->getPlanDataColumnsOrder(), 'plan_data');
    }

    #[Route('/superuser/sites/export', name: 'superuser_dashboard_sites_export', methods: ['GET'])]
    public function exportSitesCsv(Request $request, ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $service = $request->query->get('service');
        $classification = $request->query->get('classification');
        $statusFilter = $request->query->get('status');
        $search = $request->query->get('search');

        $sites = $processedSiteRepository->findSitesForExport($service, $classification, $statusFilter, $search);

        return $this->buildCsvResponse($sites, $this->getPlanDataColumnsOrder(), 'sites_export');
    }

    #[Route('/superuser/export/generate', name: 'superuser_export_generate', methods: ['POST'])]
    public function exportSites(Request $request, ProcessedSiteRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $service = $request->request->get('service_filter');
        $classification = $request->request->get('classification_filter');
        $search = $request->request->get('site_search');
        $periodStart = $request->request->get('period_start');
        $periodEnd = $request->request->get('period_end');
        $selectedColumns = $request->request->all('columns', []);

        $sites = $repo->findForAdvancedExport(
            $service && $service !== 'all' ? $service : null,
            'all',
            [],
            $search ?: '',
            $periodStart ?: null,
            $periodEnd ?: null
        );

        if ($classification && $classification !== 'all') {
            $sites = array_filter($sites, function ($site) use ($classification) {
                return strtoupper((string) $site->getClassification()) === strtoupper($classification);
            });
        }

        return $this->buildCsvResponse($sites, $selectedColumns ?: $this->getPlanDataColumnsOrder(), 'sites_export');
    }

    private function buildCsvResponse(iterable $sites, array $selectedColumns, string $filenamePrefix): Response
    {
        $availableColumns = $this->getAvailableColumns();

        if (empty($selectedColumns)) {
            $selectedColumns = $this->getPlanDataColumnsOrder();
        }
        $selectedColumns = array_values(array_intersect($selectedColumns, array_keys($availableColumns)));

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");

        $headers = [];
        foreach ($selectedColumns as $key) {
            $headers[] = $availableColumns[$key]['label'];
        }
        fputcsv($handle, $headers, ';');

        foreach ($sites as $site) {
            $row = [];
            foreach ($selectedColumns as $key) {
                $getter = $availableColumns[$key]['getter'];
                $value = $site->$getter();
                $value = $this->normalizeExportValue($key, $value);

                if ($key === 's1FailDuration') {
                    $value = ($value !== null && $value > 0) ? number_format((float) $value, 0, '.', '') : '-';
                } elseif ($key === 's1FailDate' || $key === 'capaciteUpdatedAt') {
                    $value = $value instanceof \DateTimeInterface ? $value->format('d/m/Y H:i') : '-';
                } elseif ($key === 'longitude' || $key === 'latitude') {
                    $value = $value !== null ? number_format((float) $value, 6, '.', '') : '-';
                }

                if ($value === null || $value === '') {
                    $value = '-';
                }

                if (is_numeric($value) && !is_bool($value) && !in_array($key, ['s1FailDuration', 'longitude', 'latitude', 'nombreOccurrences', 'nombreOccurrencesTdd', 'nombreOccurrencesFdd', 'dropCongTdd', 'dropCongFdd', 'dropCongTf'], true)) {
                    $value = number_format((float) $value, 2, '.', '');
                }

                $row[] = $value;
            }
            fputcsv($handle, $row, ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $filename = $filenamePrefix . '_' . date('Y-m-d_His') . '.csv';

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
