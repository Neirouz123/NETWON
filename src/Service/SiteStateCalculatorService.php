<?php
// src/Service/SiteStateCalculatorService.php
namespace App\Service;

use App\Entity\ProcessedSite;

/**
 * ✅ RÈGLE UNIQUE (alignée sur traitement.py) :
 *   status = CRITIQUE          <=> état technique = BRIDAGE/CONGESTION
 *   status = SOUS_OBSERVATION  <=> état technique = RISQUE_DE_CONGESTION
 *   status = OK                <=> état technique = OK
 * Le type de liaison manquant et S1 ne modifient JAMAIS status/siteStatus/isCritical.
 */
class SiteStateCalculatorService
{
    private const SEUIL_CONGESTION_PCT = 90.0;
    private const SEUIL_OCCURRENCES_BRIDAGE = 50;
    private const SEUIL_OCCURRENCES_RISQUE_CONGESTION = 57;
    private const CAPACITE_10G_MBPS = 10000.0;
    private const SEUIL_OCCURRENCES_VERIFICATION_CAPACITE = 20;

    public const ETATS_CRITIQUES = ['CONGESTION','BRIDAGE'];
    public const ETATS_RISQUE = ['RISQUE_DE_CONGESTION', 'RISQUE_DE_BRIDAGE'];


    public function isMissingType(?string $type): bool
    {
        $t = strtoupper(trim((string) $type));
        return $t === '' || in_array($t, ['NON_DEFINI', 'UNKNOWN', 'N/A', 'NA', '-'], true);
    }

public function recalculer(ProcessedSite $site): void
{
    $classification = strtoupper(trim((string) $site->getClassification()));
    $typeTrans = $site->getTypeTrans();

    $maxTrafic = (float) ($site->getMaxTrafic() ?? 0);
    $maxTraficTdd = (float) ($site->getMaxTraficTdd() ?? 0);
    $maxTraficFdd = (float) ($site->getMaxTraficFdd() ?? 0);

    $capaciteTdd = (float) ($site->getCapaciteTddMbps() ?? 0);
    $capaciteFdd = (float) ($site->getCapaciteFddMbps() ?? 0);
    $capaciteGlobale = (float) ($site->getCapaciteMbps() ?? 0);

    $occurrences = (int) $site->getNombreOccurrences();
    $occTdd = (int) ($site->getNombreOccurrencesTdd() ?? 0);
    $occFdd = (int) ($site->getNombreOccurrencesFdd() ?? 0);

    $tauxTdd = ($capaciteTdd > 0 && $maxTraficTdd > 0) ? round(($maxTraficTdd / $capaciteTdd) * 100, 2) : null;
    $tauxFdd = ($capaciteFdd > 0 && $maxTraficFdd > 0) ? round(($maxTraficFdd / $capaciteFdd) * 100, 2) : null;

    $classer = function (float $taux, int $occ): string {
        if ($taux >= self::SEUIL_CONGESTION_PCT) {
            return $occ >= self::SEUIL_OCCURRENCES_RISQUE_CONGESTION ? 'CONGESTION' : 'RISQUE_DE_CONGESTION';
        }
        if ($occ >= self::SEUIL_OCCURRENCES_BRIDAGE) {
            return 'BRIDAGE';
        }
        if ($occ >= self::SEUIL_OCCURRENCES_VERIFICATION_CAPACITE) {
            return 'RISQUE_DE_BRIDAGE';
        }
        return 'OK';
    };

    $gravite = ['OK' => 0, 'RISQUE_DE_BRIDAGE' => 1, 'RISQUE_DE_CONGESTION' => 2, 'BRIDAGE' => 3, 'CONGESTION' => 4];
    $pire = fn(string $a, string $b) => $gravite[$a] >= $gravite[$b] ? $a : $b;

    $tauxGlobal = null;

    if (in_array($classification, ['COTRANS', 'NON-COTRANS', 'NO_COTRANS'], true)) {
        $etat = $pire($classer($tauxFdd ?? 0, $occFdd), $classer($tauxTdd ?? 0, $occTdd));
    } elseif ($capaciteGlobale <= 0) {
        $etat = 'OK';
    } else {
        $tauxGlobal = $maxTrafic > 0 ? round(($maxTrafic / $capaciteGlobale) * 100, 2) : null;
        $taux = $tauxGlobal ?? 0.0;

        if ($classification !== 'TF'
            && abs($capaciteGlobale - self::CAPACITE_10G_MBPS) < 1.0
            && $taux >= self::SEUIL_CONGESTION_PCT
            && $occurrences >= self::SEUIL_OCCURRENCES_RISQUE_CONGESTION) {
            $etat = 'BRIDAGE';
        } else {
            $etat = $classer($taux, $occurrences);
        }
    }

    $status = in_array($etat, self::ETATS_CRITIQUES, true)
        ? 'CRITIQUE'
        : (in_array($etat, self::ETATS_RISQUE, true) ? 'SOUS_OBSERVATION' : 'OK');
    $siteStatus = $etat; // déjà dans le vocabulaire fermé

    $site->setTauxUtilisation($tauxGlobal);
    $site->setTauxUtilisationTdd($tauxTdd);
    $site->setTauxUtilisationFdd($tauxFdd);
    $site->setStatus($status);
    $site->setSiteStatus($siteStatus);
    $site->setIsCritical($status === 'CRITIQUE');

    $recommendation = $this->buildRecommendation(
        $etat, $siteStatus, $classification, $typeTrans,
        $maxTrafic, $capaciteGlobale, $tauxGlobal, $occurrences
    );
    $site->setRecommendedAction($recommendation['actionType']);
    $site->setFinalActionPlan($recommendation['actionLabel']);
}

    public function buildRecommendation(
        string $etatSite, string $siteStatus, ?string $classification,
        ?string $typeTrans, float $maxTrafic, float $capaciteMbps,
        ?float $tauxUtilisation, int $nombreOccurrences
    ): array {
        $classification = strtoupper(trim((string) $classification));
        $typeTrans = strtoupper(trim((string) $typeTrans));
        $taux = $tauxUtilisation ?? 0;

        $actionType = 'MONITORING';
        $actionLabel = 'Maintenir sous surveillance';

        switch ($etatSite) {
            case 'CONGESTION':
                $actionType = 'URGENT_UPGRADE';
                $actionLabel = 'Upgrade urgent de capacite';
                break;
            case 'CONGESTION(FDD)':
                $actionType = 'UPGRADE_FDD';
                $actionLabel = 'Upgrade porteuse FDD';
                break;
            case 'CONGESTION(TDD)':
                $actionType = 'UPGRADE_TDD';
                $actionLabel = 'Upgrade porteuse TDD';
                break;
            case 'RISQUE_DE_CONGESTION':
                $actionType = 'SURVEILLANCE_RENFORCEE';
                $actionLabel = 'Surveiller le site (proche du seuil de congestion)';
                break;
            case 'BRIDAGE':
                $actionType = 'INVESTIGATE_BRIDAGE';
                $actionLabel = 'Investiguer le bridage de trafic';
                break;
            case 'OK':
            default:
                if ($classification === 'TF') {
                    $actionType = 'TF_MONITORING';
                    $actionLabel = 'Surveillance TF renforcee';
                } elseif ($classification === 'COTRANS') {
                    $actionType = 'COTRANS_OPTIMIZATION';
                    $actionLabel = 'Optimisation COTRANS';
                } elseif ($classification === 'NO_COTRANS') {
                    $actionType = 'NO_COTRANS_REVIEW';
                    $actionLabel = 'Revue configuration NO_COTRANS';
                } elseif (in_array($classification, ['FDD', 'ONLY_FDD'])) {
                    $actionType = 'FDD_ANALYSIS';
                    $actionLabel = 'Analyse FDD approfondie';
                }
                break;
        }

        if (in_array($etatSite, ['CONGESTION', 'CONGESTION(FDD)', 'CONGESTION(TDD)'])) {
            if (str_contains($typeTrans, 'FO')) {
                $actionType = 'FO_UPGRADE';
                $actionLabel = 'Upgrade fibre optique (FO)';
            } elseif (str_contains($typeTrans, 'FH')) {
                $actionType = 'FH_UPGRADE';
                $actionLabel = 'Upgrade faisceau hertzien (FH)';
            } elseif ((str_contains($typeTrans, 'BACKBONE') || str_contains($typeTrans, 'BH')) && $taux >= 70) {
                $actionType = 'BACKBONE_UPGRADE';
                $actionLabel = 'Upgrade Backbone';
            }
        }

        return [
            'status' => $etatSite,
            'siteStatus' => $siteStatus,
            'actionType' => $actionType,
            'actionLabel' => $actionLabel,
        ];
    }
}