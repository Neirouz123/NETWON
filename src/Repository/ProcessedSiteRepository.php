<?php

namespace App\Repository;

use App\Entity\ProcessedSite;
use App\Util\SiteNameHelper;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\DBAL\ArrayParameterType;

class ProcessedSiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessedSite::class);
    }

    public function existsByDataHash(string $hash): bool
    {
        return (int) $this->createQueryBuilder('ps')
            ->select('COUNT(ps.id)')
            ->andWhere('ps.dataHash = :hash')
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function findOneBySiteName(string $siteName): ?ProcessedSite
    {
        return $this->createQueryBuilder('ps')
            ->andWhere('LOWER(ps.siteName) = :siteName')
            ->setParameter('siteName', mb_strtolower(trim($siteName)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countAllSites(?string $service = null): int
    {
        $qb = $this->createQueryBuilder('s')->select('COUNT(s.id)');
        if ($service) {
            $qb->andWhere('s.service = :service')->setParameter('service', $this->normalizeService($service));
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countCriticalSites(?string $service = null): int
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.isCritical = true');
        if ($service) {
            $qb->andWhere('s.service = :service')->setParameter('service', $this->normalizeService($service));
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findLatestSites(?string $service = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.maxTrafic', 'DESC')
            ->setMaxResults($limit);
        if ($service) {
            $qb->andWhere('p.service = :service')->setParameter('service', $this->normalizeService($service));
        }
        return $qb->getQuery()->getResult();
    }

    public function findCriticalSites(?string $service = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('ps')
            ->leftJoin('ps.processedImport', 'pi')
            ->addSelect('pi')
            ->andWhere('ps.isCritical = true')
            ->orderBy('ps.nombreOccurrences', 'DESC')
            ->addOrderBy('ps.dateMax', 'DESC')
            ->setMaxResults($limit);

        if ($service) {
            $qb->andWhere('ps.service = :service')->setParameter('service', $this->normalizeService($service));
        }

        return $qb->getQuery()->getResult();
    }

    public function findDistinctSiteNames(?string $service = null): array
    {
        $qb = $this->createQueryBuilder('ps')
            ->select('DISTINCT ps.siteName AS siteName')
            ->orderBy('ps.siteName', 'ASC');

        if ($service) {
            $qb->andWhere('ps.service = :service')->setParameter('service', $this->normalizeService($service));
        }

        $rows = $qb->getQuery()->getArrayResult();
        return array_map(fn($row) => $row['siteName'], $rows);
    }

    public function getStatsByService(): array
    {
        return $this->createQueryBuilder('ps')
            ->select('ps.service AS service')
            ->addSelect('COUNT(ps.id) AS totalSites')
            ->addSelect('SUM(CASE WHEN ps.isCritical = true THEN 1 ELSE 0 END) AS criticalSites')
            ->andWhere('ps.service IS NOT NULL')
            ->groupBy('ps.service')
            ->orderBy('ps.service', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    public function getServiceDistribution(): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('s.service, COUNT(s.id) as count')
            ->where('s.service IS NOT NULL')
            ->andWhere("s.service <> ''")
            ->groupBy('s.service');
        $results = $qb->getQuery()->getResult();
        $dist = [];
        foreach ($results as $row) {
            $dist[$row['service']] = (int) $row['count'];
        }
        return $dist;
    }

    public function getClassificationStats(?string $service = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('s.classification, COUNT(s.id) as total, SUM(CASE WHEN s.isCritical = true THEN 1 ELSE 0 END) as critical')
            ->groupBy('s.classification');
        if ($service) {
            $qb->andWhere('s.service = :service')->setParameter('service', $this->normalizeService($service));
        }
        $results = $qb->getQuery()->getResult();
        $stats = [];
        foreach ($results as $row) {
            $stats[$row['classification']] = [
                'total' => (int) $row['total'],
                'critical' => (int) $row['critical'],
            ];
        }
        return $stats;
    }

    public function getAverageTraffic(?string $service = null): float
    {
        $qb = $this->createQueryBuilder('s')->select('AVG(s.maxTrafic) as avgTraffic');
        if ($service) {
            $qb->andWhere('s.service = :service')->setParameter('service', $this->normalizeService($service));
        }
        $result = $qb->getQuery()->getSingleScalarResult();
        return $result ? (float) $result : 0.0;
    }

    public function findSitesPaginated(
        ?string $service,
        ?string $classification,
        ?string $search,
        int $page = 1,
        int $limit = 50,
        ?string $statusFilter = null
    ): array {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $qb = $this->createQueryBuilder('ps')
            ->leftJoin('ps.processedImport', 'pi')
            ->addSelect('pi');

        if ($service) {
            $qb->andWhere('ps.service = :service')->setParameter('service', $this->normalizeService($service));
        }
        if ($classification) {
            $qb->andWhere('ps.classification = :classification')->setParameter('classification', $classification);
        }
        if ($search) {
            $qb->andWhere('LOWER(ps.siteName) LIKE :search OR LOWER(ps.pairedSiteName) LIKE :search')
                ->setParameter('search', '%' . mb_strtolower(trim($search)) . '%');
        }

        $this->applyStatusFilter($qb, $statusFilter);

        $countQb = clone $qb;
        $total = (int) $countQb
            ->resetDQLPart('orderBy')
            ->select('COUNT(ps.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb
            ->orderBy('ps.dateMax', 'DESC')
            ->addOrderBy('ps.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => max(1, (int) ceil($total / $limit)),
        ];
    }

    private function applyStatusFilter($qb, ?string $statusFilter): void
    {
        if (!$statusFilter) {
            return;
        }

        switch (strtoupper($statusFilter)) {
            case 'SANS_CAPACITE':
                $qb->andWhere('(ps.capaciteMbps IS NULL OR ps.capaciteMbps <= 0)');
                break;
            case 'SANS_TYPE':
                $qb->andWhere('(ps.typeTrans IS NULL OR ps.typeTrans IN (:missingTypes))')
                    ->setParameter('missingTypes', ['', 'NON_DEFINI', 'UNKNOWN', 'N/A', 'NA', '-']);
                break;
            case 'SECURISE':
                $qb->andWhere('UPPER(ps.status) = :statusVal')->setParameter('statusVal', 'OK');
                break;
            case 'CRITIQUE':
                $qb->andWhere('UPPER(ps.status) = :statusVal')->setParameter('statusVal', 'CRITIQUE');
                break;
            case 'SURVEILLANCE':
                $qb->andWhere('UPPER(ps.status) = :statusVal')->setParameter('statusVal', 'SOUS_OBSERVATION');
                break;
            case 'CONGESTION':
                $qb->andWhere('UPPER(ps.siteStatus) = :etatVal')->setParameter('etatVal', 'CONGESTION');
                break;
            case 'BRIDAGE':
                $qb->andWhere('UPPER(ps.siteStatus) = :etatVal')->setParameter('etatVal', 'BRIDAGE');
                break;
            case 'RISQUE_DE_CONGESTION':
                $qb->andWhere('UPPER(ps.siteStatus) = :etatVal')->setParameter('etatVal', 'RISQUE_DE_CONGESTION');
                break;
            case 'RISQUE_DE_BRIDAGE':
                $qb->andWhere('UPPER(ps.siteStatus) = :etatVal')->setParameter('etatVal', 'RISQUE_DE_BRIDAGE');
                break;
            case 'COUPURE_S1':
                $qb->andWhere('ps.s1FailDuration > 0');
                break;
            case 'NON_EVALUE':
                $qb->andWhere('(ps.siteStatus IS NULL OR UPPER(ps.siteStatus) = :statusVal)')->setParameter('statusVal', 'NON_EVALUE');
                break;
            default:
                break;
        }
    }

    public static function getStatusFilterOptions(): array
    {
        return [
            'SANS_CAPACITE' => 'Sans capacité',
            'SANS_TYPE' => 'Sans type de liaison',
            'SECURISE' => 'OK / Sécurisé',
            'SURVEILLANCE' => 'Sous observation',
            'CRITIQUE' => 'Critique',
            'CONGESTION' => 'Congestion',
            'BRIDAGE' => 'Bridage',
            'RISQUE_DE_CONGESTION' => 'Risque de congestion',
            'RISQUE_DE_BRIDAGE' => 'Risque de bridage',
            'COUPURE_S1' => 'Coupure S1',
            'NON_EVALUE' => 'Non évalué',
        ];
    }
    public function findForAdvancedExport(
        ?string $service,
        string $mode = 'all',
        array $siteNames = [],
        string $siteSearch = '',
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $qb = $this->createQueryBuilder('ps')
            ->orderBy('ps.dateMax', 'DESC')
            ->addOrderBy('ps.id', 'DESC');

        if ($service) {
            $qb->andWhere('ps.service = :service')->setParameter('service', $this->normalizeService($service));
        }
        if ($mode === 'selected' && !empty($siteNames)) {
            $qb->andWhere('ps.siteName IN (:siteNames)')->setParameter('siteNames', $siteNames);
        }
        if ($siteSearch !== '') {
            $qb->andWhere('LOWER(ps.siteName) LIKE :siteSearch OR LOWER(ps.pairedSiteName) LIKE :siteSearch')
                ->setParameter('siteSearch', '%' . mb_strtolower(trim($siteSearch)) . '%');
        }
        if ($dateFrom) {
            $qb->andWhere('ps.dateMax >= :dateFrom')->setParameter('dateFrom', new \DateTime($dateFrom . ' 00:00:00'));
        }
        if ($dateTo) {
            $qb->andWhere('ps.dateMax <= :dateTo')->setParameter('dateTo', new \DateTime($dateTo . ' 23:59:59'));
        }

        return $qb->getQuery()->getResult();
    }

    private function normalizeService(?string $service): ?string
    {
        $service = strtoupper(trim((string) $service));
        if ($service === '' || $service === 'NON_DEFINI' || $service === 'UNKNOWN' || $service === 'N/A' || $service === 'NA' || $service === '-') {
            return null;
        }
        if ($service === 'FO') return 'FO';
        if ($service === 'FH') return 'FH';
        if ($service === 'BACKBONE') return 'SHARED';
        return 'SHARED';
    }

    public function countSecureSites(?string $service = null): int
    {
        $qb = $this->createQueryBuilder('ps')
            ->select('COUNT(ps.id)')
            ->where('ps.siteStatus = :status')
            ->setParameter('status', 'SECURISE');
        if ($service) {
            $qb->andWhere('ps.service = :service')->setParameter('service', $this->normalizeService($service));
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countWarningSites(?string $service = null): int
    {
        $qb = $this->createQueryBuilder('ps')
            ->select('COUNT(ps.id)')
            ->where('ps.siteStatus = :status')
            ->setParameter('status', 'SURVEILLANCE');
        if ($service) {
            $qb->andWhere('ps.service = :service')->setParameter('service', $this->normalizeService($service));
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findSitesWithCoordinates(
        ?string $service = null,
        ?string $classification = null,
        ?string $critical = null
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->select('s.siteName, s.latitude, s.longitude, s.isCritical, s.siteStatus, s.maxTrafic, s.service, s.classification, s.typeTrans')
            ->where('s.latitude IS NOT NULL')
            ->andWhere('s.longitude IS NOT NULL');

        if ($service) {
            $qb->andWhere('s.service = :service')->setParameter('service', $this->normalizeService($service));
        }
        if ($classification) {
            $qb->andWhere('s.classification = :classification')->setParameter('classification', $classification);
        }
        if ($critical === '1') {
            $qb->andWhere('s.isCritical = true');
        } elseif ($critical === '0') {
            $qb->andWhere('s.isCritical = false');
        }

        return $qb->getQuery()->getArrayResult();
    }

    public function findAllSitePairs(?string $service = null): array
    {
        $qb = $this->createQueryBuilder('ps')
            ->select('DISTINCT ps.siteName as prefix, ps.service')
            ->where('ps.siteName IS NOT NULL')
            ->andWhere('ps.classification IS NOT NULL');
        if ($service) {
            $qb->andWhere('ps.service = :service')->setParameter('service', $this->normalizeService($service));
        }
        return $qb->getQuery()->getArrayResult();
    }

    public function findSitesNeedingCapacityUpdate(int $limit = 20): array
    {
        $now = new \DateTime();
        return $this->createQueryBuilder('ps')
            ->andWhere('(ps.capaciteMbps IS NULL OR ps.capaciteMbps <= 0 OR ps.typeTrans IS NULL OR ps.typeTrans IN (:missingTypes))')
            ->andWhere('(ps.capacityReminderUntil IS NULL OR ps.capacityReminderUntil < :now)')
            ->setParameter('missingTypes', ['', 'NON_DEFINI', 'UNKNOWN', 'N/A', 'NA', '-'])
            ->setParameter('now', $now)
            ->orderBy('ps.isCritical', 'DESC')
            ->addOrderBy('ps.maxTrafic', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    // ================== HISTORIQUE ==================

    public function getTrafficHistoryForPrefix(string $prefix, ?int $days = null): array
    {
        $conn = $this->getEntityManager()->getConnection();
        if ($days !== null) {
            $sql = "
                SELECT
                    date_heure AS date_jour,
                    SUM(max_speed) AS trafic_total
                FROM trafic_historique
                WHERE site LIKE :prefixPattern
                    AND date_heure >= (
                        SELECT MAX(date_heure) - INTERVAL '$days days'
                        FROM trafic_historique
                        WHERE site LIKE :prefixPattern2
                    )
                GROUP BY date_heure
                ORDER BY date_heure ASC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue('prefixPattern', $prefix . '%');
            $stmt->bindValue('prefixPattern2', $prefix . '%');
        } else {
            $sql = "
                SELECT
                    date_heure AS date_jour,
                    SUM(max_speed) AS trafic_total
                FROM trafic_historique
                WHERE site LIKE :prefixPattern
                GROUP BY date_heure
                ORDER BY date_heure ASC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue('prefixPattern', $prefix . '%');
        }
        return $stmt->executeQuery()->fetchAllAssociative();
    }

    public function getTrafficHistoryForSiteExact(string $siteName, int $days = 30): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $maxDateSql = "SELECT MAX(date_heure) FROM trafic_historique WHERE site = :site";
        $maxStmt = $conn->prepare($maxDateSql);
        $maxStmt->bindValue('site', $siteName);
        $maxDateResult = $maxStmt->executeQuery()->fetchOne();
        $maxDate = $maxDateResult ? new \DateTime($maxDateResult) : new \DateTime();
        $startDate = (clone $maxDate)->modify("-$days days");

        $sql = "
            SELECT
                date_heure AS date_jour,
                max_speed AS trafic_total
            FROM trafic_historique
            WHERE site = :site
                AND date_heure >= :startDate
                AND date_heure <= :endDate
            ORDER BY date_heure ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('site', $siteName);
        $stmt->bindValue('startDate', $startDate->format('Y-m-d H:i:s'));
        $stmt->bindValue('endDate', $maxDate->format('Y-m-d H:i:s'));
        return $stmt->executeQuery()->fetchAllAssociative();
    }

    public function getTrafficHistoryForPrefixBetween(string $prefix, \DateTime $start, \DateTime $end): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = "
            SELECT
                date_heure AS date_jour,
                SUM(max_speed) AS trafic_total
            FROM trafic_historique
            WHERE site LIKE :prefixPattern
                AND date_heure >= :start AND date_heure <= :end
            GROUP BY date_heure
            ORDER BY date_heure ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('prefixPattern', $prefix . '%');
        $stmt->bindValue('start', $start->format('Y-m-d H:i:s'));
        $stmt->bindValue('end', $end->format('Y-m-d H:i:s'));
        return $stmt->executeQuery()->fetchAllAssociative();
    }

    public function getTrafficHistoryForSiteExactBetween(string $siteName, \DateTime $start, \DateTime $end): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = "
            SELECT
                date_heure AS date_jour,
                max_speed AS trafic_total
            FROM trafic_historique
            WHERE site = :site
                AND date_heure >= :start AND date_heure <= :end
            ORDER BY date_heure ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('site', $siteName);
        $stmt->bindValue('start', $start->format('Y-m-d H:i:s'));
        $stmt->bindValue('end', $end->format('Y-m-d H:i:s'));
        return $stmt->executeQuery()->fetchAllAssociative();
    }

    public function findDistinctRawSiteNamesForPrefix(string $prefix): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $stmt = $conn->prepare("SELECT DISTINCT site FROM trafic_historique WHERE site LIKE :prefixPattern ORDER BY site");
        $stmt->bindValue('prefixPattern', $prefix . '%');
        $rows = $stmt->executeQuery()->fetchAllAssociative();
        return array_map(fn($row) => $row['site'], $rows);
    }

    // ================== AUTRES MÉTHODES ==================

    public function getSiblingSitesWithCapacities(string $prefix): array
    {
        $rawNames = $this->findDistinctRawSiteNamesForPrefix($prefix);

        $tdd = null;
        $fdd = null;
        foreach ($rawNames as $name) {
            if (SiteNameHelper::isTdd($name)) {
                $tdd = $name;
            } elseif (SiteNameHelper::isFdd($name)) {
                $fdd = $name;
            }
        }

        $conn = $this->getEntityManager()->getConnection();
        $capacities = [];
        foreach (['tdd' => $tdd, 'fdd' => $fdd] as $key => $siteName) {
            if ($siteName) {
                $stmt = $conn->prepare("SELECT capacite_mbps FROM capacite_site WHERE site = :site");
                $stmt->bindValue('site', $siteName);
                $cap = $stmt->executeQuery()->fetchOne();
                $capacities[$key] = $cap !== false ? (float) $cap : null;
            } else {
                $capacities[$key] = null;
            }
        }

        return [
            'tdd' => ['siteName' => $tdd, 'capacity' => $capacities['tdd']],
            'fdd' => ['siteName' => $fdd, 'capacity' => $capacities['fdd']],
        ];
    }

    public function getCapaciteForSite(string $siteName): ?float
    {
        $conn = $this->getEntityManager()->getConnection();
        $stmt = $conn->prepare("SELECT capacite_mbps FROM capacite_site WHERE site = :site");
        $stmt->bindValue('site', $siteName);
        $result = $stmt->executeQuery()->fetchOne();
        return $result !== false ? (float) $result : null;
    }

    public function getKpiCurvesDataForPrefix(string $prefix, ?\DateTime $start = null, ?\DateTime $end = null, int $days = 30): array
    {
        if ($start === null || $end === null) {
            $conn = $this->getEntityManager()->getConnection();
            $stmt = $conn->prepare("SELECT MAX(date_heure) FROM trafic_historique WHERE site LIKE :prefixPattern");
            $stmt->bindValue('prefixPattern', $prefix . '%');
            $latest = $stmt->executeQuery()->fetchOne();

            $end = $latest ? new \DateTime($latest) : new \DateTime();
            $end->setTime(23, 59, 59);
            $start = (clone $end)->modify("-$days days");
        }

        $mainSite = $this->findOneBy(['siteName' => $prefix]);

        $capaciteTdd = $mainSite ? $mainSite->getCapaciteTddMbps() : null;
        $capaciteFdd = $mainSite ? $mainSite->getCapaciteFddMbps() : null;

        $rawNames = $this->findDistinctRawSiteNamesForPrefix($prefix);
        $tddSite = null;
        $fddSite = null;
        foreach ($rawNames as $name) {
            if (SiteNameHelper::isTdd($name)) {
                $tddSite = $name;
            } elseif (SiteNameHelper::isFdd($name)) {
                $fddSite = $name;
            }
        }

        if (!$fddSite && !$tddSite) {
            $fddSite = $prefix;
        }

        $totalHistory = $this->getTrafficHistoryForPrefixBetween($prefix, $start, $end);
        $tddHistory = $tddSite ? $this->getTrafficHistoryForSiteExactBetween($tddSite, $start, $end) : [];
        $fddHistory = $fddSite ? $this->getTrafficHistoryForSiteExactBetween($fddSite, $start, $end) : [];

        $totalSeries = [];
        $tddSeries = [];
        $fddSeries = [];
        $timeline = [];

        $addSeries = function ($history, &$series, &$timeline) {
            foreach ($history as $row) {
                $timestamp = strtotime((string) ($row['date_jour'] ?? ''));
                if ($timestamp === false) continue;
                $x = $timestamp * 1000;
                $val = isset($row['trafic_total']) ? (float) $row['trafic_total'] : 0;
                $series[] = ['x' => $x, 'y' => round($val, 2)];
                $timeline[$x] = true;
            }
        };

        $addSeries($totalHistory, $totalSeries, $timeline);
        $addSeries($tddHistory, $tddSeries, $timeline);
        $addSeries($fddHistory, $fddSeries, $timeline);

        $hasTrafficData = !empty($timeline);

        $capacitySeries = ['tdd' => [], 'fdd' => [], 'total' => []];
        $totalCapacity = max((float) ($capaciteTdd ?? 0), (float) ($capaciteFdd ?? 0));

        if ($hasTrafficData) {
            ksort($timeline);
            $timelinePoints = array_keys($timeline);
            foreach ($timelinePoints as $x) {
                if ($capaciteTdd !== null) {
                    $capacitySeries['tdd'][] = ['x' => $x, 'y' => round((float) $capaciteTdd, 2)];
                }
                if ($capaciteFdd !== null) {
                    $capacitySeries['fdd'][] = ['x' => $x, 'y' => round((float) $capaciteFdd, 2)];
                }
                if ($totalCapacity > 0) {
                    $capacitySeries['total'][] = ['x' => $x, 'y' => round($totalCapacity, 2)];
                }
            }
        }

        $totalTrafficValues = array_column($totalSeries, 'y');
        $avgTrafficTotal = !empty($totalTrafficValues) ? round(array_sum($totalTrafficValues) / count($totalTrafficValues), 2) : 0.0;
        $maxTrafficTotal = !empty($totalTrafficValues) ? max($totalTrafficValues) : 0.0;
        $availability = $mainSite && !$mainSite->isS1Down() ? 100.0 : ($mainSite ? 0.0 : null);

        return [
            'prefix' => $prefix,
            'siblings' => ['tdd' => $tddSite, 'fdd' => $fddSite],
            'hasTrafficData' => $hasTrafficData,
            'series' => [
                'traffic' => ['total' => $totalSeries, 'tdd' => $tddSeries, 'fdd' => $fddSeries],
                'capacity' => $capacitySeries,
            ],
            'capacities' => [
                'tdd' => $capaciteTdd !== null ? round((float) $capaciteTdd, 2) : null,
                'fdd' => $capaciteFdd !== null ? round((float) $capaciteFdd, 2) : null,
                'total' => $totalCapacity > 0 ? round($totalCapacity, 2) : null,
            ],
            'stats' => [
                'avgTrafficTotal' => $avgTrafficTotal,
                'maxTrafficTotal' => round((float) $maxTrafficTotal, 2),
                'availability' => $availability,
            ],
            'dataRange' => [
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ],
        ];
    }

    public function findAllSitesOrderedByStatus(
        ?string $service = null,
        ?string $classification = null,
        string $search = ''
    ): array {
        $qb = $this->createQueryBuilder('ps')
            ->leftJoin('ps.processedImport', 'pi')
            ->addSelect('pi');

        if ($service) {
            $qb->andWhere('ps.service = :service')->setParameter('service', $this->normalizeService($service));
        }
        if ($classification) {
            $qb->andWhere('ps.classification = :classification')->setParameter('classification', $classification);
        }
        if ($search !== '') {
            $qb->andWhere('LOWER(ps.siteName) LIKE :search OR LOWER(ps.pairedSiteName) LIKE :search')
                ->setParameter('search', '%' . mb_strtolower(trim($search)) . '%');
        }

        return $qb
            ->addSelect("
                CASE
                    WHEN ps.siteStatus = 'CRITIQUE' THEN 0
                    WHEN ps.siteStatus = 'SURVEILLANCE' THEN 1
                    WHEN ps.siteStatus = 'SECURISE' THEN 2
                    ELSE 3
                END as HIDDEN statusOrder
            ")
            ->orderBy('statusOrder', 'ASC')
            ->addOrderBy('ps.nombreOccurrences', 'DESC')
            ->addOrderBy('ps.maxTrafic', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getCapacitiesExportData(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = "
            SELECT
                ps.site_name AS site,
                ps.type_trans,
                ps.capacite_tdd_mbps AS capacite_tdd,
                ps.capacite_fdd_mbps AS capacite_fdd,
                ps.date_max AS derniere_mise_a_jour,
                u.username AS mis_a_jour_par
            FROM processed_site ps
            LEFT JOIN processed_import pi ON ps.processed_import_id = pi.id
            LEFT JOIN \"user\" u ON pi.imported_by_id = u.id
            WHERE ps.capacite_tdd_mbps IS NOT NULL OR ps.capacite_fdd_mbps IS NOT NULL
            ORDER BY ps.site_name
        ";
        $stmt = $conn->prepare($sql);
        return $stmt->executeQuery()->fetchAllAssociative();
    }

    // ================== ALERTES RÉSEAU (site_alert) ==================

    /**
     * ✅ Vraies alertes réseau granulaires (message formaté, état précis,
     * date exacte) issues de site_alert, alimentée par le pipeline Python
     * (analyser_etats_et_alertes dans traitement.py).
     */
    public function findRecentSiteAlerts(?string $service = null, int $limit = 100): array
    {
        $conn = $this->getEntityManager()->getConnection();

        if (!$this->tableExists($conn, 'site_alert')) {
            return [];
        }

        $sql = "
        SELECT sa.id, sa.site, sa.etat, sa.trafic_j, sa.capacite_mbps,
               sa.taux_utilisation,
               sa.type_trans, sa.classification, sa.message, sa.date_alerte,
               ps.service AS resolved_service
        FROM site_alert sa
        LEFT JOIN processed_site ps ON ps.site_name = sa.site
        WHERE sa.type_trans != 'IA_ANOMALY'
    ";
        $params = [];
        if ($service) {
            $sql .= " AND ps.service = :service";
            $params['service'] = $this->normalizeService($service);
        }
        $sql .= " ORDER BY sa.date_alerte DESC LIMIT :limit";

        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, ParameterType::INTEGER);

        return $stmt->executeQuery()->fetchAllAssociative();
    }

    /**
     * ✅ Compte les alertes site_alert des 7 derniers jours par catégorie
     * (congestion/bridage/risque/sans_type), pour le graphique et les
     * compteurs de la page Alertes.
     */
    public function getSiteAlertCounts(?string $service = null): array
    {
        $counts = [
            'CONGESTION' => 0,
            'BRIDAGE' => 0,
            'RISQUE_DE_CONGESTION' => 0,
            'SANS_TYPE' => 0,
            'A_VERIFIER_CAPACITE' => 0,
        ];

        $conn = $this->getEntityManager()->getConnection();

        if (!$this->tableExists($conn, 'site_alert')) {
            return $counts;
        }

        $sql = "
            SELECT sa.etat, COUNT(*) as total
            FROM site_alert sa
            LEFT JOIN processed_site ps ON ps.site_name = sa.site
            WHERE sa.type_trans != 'IA_ANOMALY'
              AND sa.date_alerte >= NOW() - INTERVAL '7 days'
        ";
        $params = [];
        if ($service) {
            $sql .= " AND ps.service = :service";
            $params['service'] = $this->normalizeService($service);
        }
        $sql .= " GROUP BY sa.etat";

        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $rows = $stmt->executeQuery()->fetchAllAssociative();

        foreach ($rows as $row) {
            $etat = $row['etat'];
            if (str_starts_with($etat, 'CONGESTION')) {
                $counts['CONGESTION'] += (int) $row['total'];
            } elseif (isset($counts[$etat])) {
                $counts[$etat] += (int) $row['total'];
            }
        }
        return $counts;
    }

    /**
     * ✅ Vérifie l'existence d'une table avant de l'interroger, pour
     * éviter un 500 (TableNotFoundException) tant que le pipeline Python
     * n'a pas encore créé certaines tables auxiliaires (site_alert,
     * site_etat...) lors du tout premier import.
     */
    private function tableExists(Connection $conn, string $tableName): bool
    {
        static $cache = [];
        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }
        $exists = (bool) $conn->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = :t)",
            ['t' => $tableName]
        );
        $cache[$tableName] = $exists;
        return $exists;
    }
    public function findClassificationsForSiteNames(array $siteNames): array
    {
        if (empty($siteNames)) {
            return [];
        }

        // Découpage en lots pour éviter une clause IN() trop volumineuse (limite de paramètres SQL)
        $map = [];
        foreach (array_chunk($siteNames, 500) as $chunk) {
            $rows = $this->createQueryBuilder('s')
                ->select('s.siteName as siteName', 's.classification as classification')
                ->where('s.siteName IN (:names)')
                ->setParameter('names', $chunk)
                ->getQuery()
                ->getArrayResult();

            foreach ($rows as $r) {
                $map[$r['siteName']] = $r['classification'];
            }
        }
        return $map;
    }

    /**
     * Retourne les périodes hebdomadaires disponibles dans trafic_historique,
     * pour peupler le filtre de période sur la page d'export.
     * Format attendu par le template : period.start, period.end, period.label
     */
    public function getAvailableImportWeeks(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
        SELECT DISTINCT
            DATE_TRUNC('week', COALESCE(date_heure, date_jour::timestamp)) AS week_start
        FROM trafic_historique
        WHERE date_heure IS NOT NULL OR date_jour IS NOT NULL
        ORDER BY week_start DESC
        LIMIT 26
    ";

        $rows = $conn->fetchAllAssociative($sql);

        $weeks = [];
        foreach ($rows as $row) {
            if (!$row['week_start']) {
                continue;
            }
            $start = new \DateTime($row['week_start']);
            $end = (clone $start)->modify('+6 days');
            $weeks[] = [
                'start' => $start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
                'label' => 'Semaine du ' . $start->format('d/m/Y') . ' au ' . $end->format('d/m/Y'),
            ];
        }

        return $weeks;
    }

    /**
     * Retourne les sites correspondant aux filtres pour l'export CSV,
     * avec les mêmes filtres que findSitesPaginated() mais sans pagination
     * (toutes les lignes filtrées sont retournées).
     */
    public function findSitesForExport(
        ?string $service,
        ?string $classification,
        ?string $status,
        ?string $search
    ): array {
        $qb = $this->createQueryBuilder('s');

        if ($service) {
            $qb->andWhere('s.service = :service')->setParameter('service', $service);
        }
        if ($classification) {
            $qb->andWhere('s.classification = :classification')->setParameter('classification', $classification);
        }
        if ($status) {
            $status = strtoupper($status);
            if ($status === 'SECURISE') $status = 'OK';
            if ($status === 'SURVEILLANCE') $status = 'SOUS_OBSERVATION';
            if (in_array($status, ['CRITIQUE', 'SOUS_OBSERVATION', 'OK'], true)) {
                $qb->andWhere('UPPER(s.status) = :status')->setParameter('status', $status);
            } elseif ($status === 'CONGESTION') {
                $qb->andWhere('UPPER(s.siteStatus) IN (:statusValues)')
                    ->setParameter('statusValues', ['CONGESTION', 'CONGESTION(FDD)', 'CONGESTION(TDD)']);
            } else {
                $qb->andWhere('UPPER(s.siteStatus) = :status')->setParameter('status', $status);
            }
        }
        if ($search) {
            $qb->andWhere('LOWER(s.siteName) LIKE :search')
                ->setParameter('search', '%' . mb_strtolower(trim($search)) . '%');
        }

        return $qb->orderBy('s.siteName', 'ASC')->getQuery()->getResult();
    }
    /**
     * Compte les alertes par état pour les sites d'un service donné, sur les N derniers jours
     */
    public function countByEtatForService(?string $service, int $days = 7): array
    {
        $date = new \DateTime("-{$days} days");
        $qb = $this->createQueryBuilder('a')
            ->select('a.etat, COUNT(a.id) as count')
            ->where('a.date_alerte >= :date')
            ->setParameter('date', $date);

        if ($service) {
            // Jointure avec processed_site pour filtrer par service
            $qb->innerJoin('App\Entity\ProcessedSite', 'ps', 'WITH', 'ps.siteName = a.site')
                ->andWhere('ps.service = :service')
                ->setParameter('service', $service);
        }

        $qb->groupBy('a.etat');

        $results = $qb->getQuery()->getResult();
        $counts = [];
        foreach ($results as $row) {
            $counts[$row['etat']] = (int) $row['count'];
        }
        return $counts;
    }
    /**
     * Compte les sites ayant un statut donné pour un service donné (ou tous si null).
     */
    public function countBySiteStatus(string $status, ?string $service = null): int
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.status = :status')
            ->setParameter('status', in_array(strtoupper($status), ['SECURISE', 'SECURE'], true) ? 'OK' : (strtoupper($status) === 'SURVEILLANCE' ? 'SOUS_OBSERVATION' : strtoupper($status)));

        if ($service) {
            $qb->andWhere('s.service = :service')
                ->setParameter('service', $this->normalizeService($service));
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }


    /**
     * Récupère les données de trafic (max_speed) pour une liste de noms de sites,
     * sur les N derniers jours, en une seule requête GROUP BY site + date.
     * Retourne un tableau associatif [ siteName => ['labels' => [...], 'values' => [...]] ]
     */
    public function getTrafficHistoryBatch(array $siteNames, int $days = 30): array
    {
        if (empty($siteNames)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        $maxDateSql = "SELECT MAX(date_heure) FROM trafic_historique";
        $maxDateResult = $conn->fetchOne($maxDateSql);
        $maxDate = $maxDateResult ? new \DateTime($maxDateResult) : new \DateTime();
        $startDate = (clone $maxDate)->modify("-$days days");

        $sql = "
        SELECT site, date_heure, max_speed
        FROM trafic_historique
        WHERE site IN (:sites)
          AND date_heure >= :startDate
          AND date_heure <= :endDate
        ORDER BY site, date_heure ASC
    ";

        // ✅ Utilisation des bons types : ArrayParameterType::STRING pour le tableau,
        // et ParameterType::STRING pour les chaînes (ou on peut les omettre).
        $stmt = $conn->executeQuery(
            $sql,
            [
                'sites' => $siteNames,
                'startDate' => $startDate->format('Y-m-d H:i:s'),
                'endDate' => $maxDate->format('Y-m-d H:i:s'),
            ],
            [
                'sites' => ArrayParameterType::STRING,
                // Les autres paramètres sont automatiquement traités comme des chaînes
                // On peut donc ne pas spécifier de type.
            ]
        );

        $rows = $stmt->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $site = $row['site'];
            if (!isset($result[$site])) {
                $result[$site] = ['labels' => [], 'values' => []];
            }
            $timestamp = strtotime($row['date_heure']);
            if ($timestamp === false) continue;
            $result[$site]['labels'][] = date('d/m H:i', $timestamp);
            $result[$site]['values'][] = (float) $row['max_speed'];
        }

        return $result;
    }
    // src/Repository/ProcessedSiteRepository.php

    // Ajoutez la méthode suivante (si ce n'est pas déjà fait) :
    public function getTrafficHistoryForPrefixes(array $prefixes, int $days = 30): array
    {
        if (empty($prefixes)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        $conditions = [];
        $params = [];
        foreach ($prefixes as $i => $prefix) {
            $conditions[] = "site LIKE :prefix_$i";
            $params["prefix_$i"] = $prefix . '%';
        }
        $whereClause = implode(' OR ', $conditions);

        $maxDateSql = "SELECT MAX(date_heure) FROM trafic_historique";
        $maxDateResult = $conn->fetchOne($maxDateSql);
        $maxDate = $maxDateResult ? new \DateTime($maxDateResult) : new \DateTime();
        $startDate = (clone $maxDate)->modify("-$days days");

        $sql = "
        SELECT site, date_heure, max_speed
        FROM trafic_historique
        WHERE ($whereClause)
          AND date_heure >= :startDate
          AND date_heure <= :endDate
        ORDER BY site, date_heure ASC
    ";

        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('startDate', $startDate->format('Y-m-d H:i:s'));
        $stmt->bindValue('endDate', $maxDate->format('Y-m-d H:i:s'));
        $rows = $stmt->executeQuery()->fetchAllAssociative();

        $rawToPrefix = [];
        foreach ($prefixes as $prefix) {
            $rawNames = $this->findDistinctRawSiteNamesForPrefix($prefix);
            foreach ($rawNames as $raw) {
                $rawToPrefix[$raw] = $prefix;
            }
        }

        $grouped = [];
        foreach ($rows as $row) {
            $rawSite = $row['site'];
            $prefix = $rawToPrefix[$rawSite] ?? null;
            if (!$prefix) continue;
            $timestamp = strtotime($row['date_heure']);
            if ($timestamp === false) continue;
            $value = (float) $row['max_speed'];
            if (!isset($grouped[$prefix])) $grouped[$prefix] = [];
            if (!isset($grouped[$prefix][$timestamp])) {
                $grouped[$prefix][$timestamp] = $value;
            } else {
                $grouped[$prefix][$timestamp] = max($grouped[$prefix][$timestamp], $value);
            }
        }

        $result = [];
        foreach ($grouped as $prefix => $data) {
            ksort($data);
            $labels = [];
            $values = [];
            foreach ($data as $ts => $val) {
                $labels[] = date('d/m H:i', $ts);
                $values[] = round($val, 2);
            }
            $result[$prefix] = ['labels' => $labels, 'values' => $values];
        }
        foreach ($prefixes as $prefix) {
            if (!isset($result[$prefix])) {
                $result[$prefix] = ['labels' => [], 'values' => []];
            }
        }
        return $result;
    }
}
