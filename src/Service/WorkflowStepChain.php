<?php
// src/Service/WorkflowStepChain.php

namespace App\Service;

/**
 * Référentiel unique des chaînes d'étapes par service.
 * Chaque TicketSite avance dans la chaîne correspondant à SON service
 * (FO, FH, SHARED...), indépendamment des autres sites du même workflow.
 */
class WorkflowStepChain
{
    // service => [ [stepCode, title, description, target], ... ]
    // target = "service:XXX" ou "department:xxx"
    private const CHAINS = [
        'FO' => [
            ['fo_initial_analysis', 'Étude initiale IP', 'Analyser la demande et décider OK / NOK.', 'department:ingenierie_ip'],
            ['fo_wo_ip_creation', 'Création WO IP', 'Créer le Work Order IP.', 'department:ingenierie_ip'],
            ['fo_deployment_planning', 'Planification déploiement', 'Planifier le déploiement.', 'department:deploiment_telecom'],
            ['fo_site_execution', 'Exécution site', 'Exécuter la demande sur le terrain.', 'department:deploiment_telecom'],
            ['fo_final_validation', 'Validation finale', 'Valider définitivement le site.', 'department:deploiment_telecom'],
        ],
        'FH' => [
            ['fh_etude_prerequis', 'Étude des prérequis FH', 'Étude des prérequis transmission.', 'department:ingenierie_capillaire'],
            ['fh_maj_capacite', 'MAJ Capacité', 'Mettre à jour la capacité.', 'department:ingenierie_capillaire'],
            ['fh_ing_trans_cap', 'Ingénierie capillaire', 'Étude ingénierie transmission.', 'department:ingenierie_capillaire'],
            ['fh_mlo', 'MLO', 'Traitement MLO.', 'department:deploiment_telecom'],
            ['fh_lld', 'LLD', 'Traitement LLD.',  'department:ingenierie_ip'],
            ['fh_execution_wo', 'Exécution WO', 'Exécuter le WO.',  'department:support_trans'],
        ],
        'SHARED' => [
            ['shared_initial_analysis', 'Étude initiale', 'Analyser la demande et décider OK / NOK.', 'service:SHARED'],
            ['shared_deployment', 'Déploiement', 'Exécuter le déploiement.', 'service:DEPLOIEMENT'],
            ['shared_final_validation', 'Validation finale', 'Valider définitivement le site.', 'service:DEPLOIEMENT'],
        ],
    ];

    public function totalStepsFor(string $service): int
    {
        return count(self::CHAINS[strtoupper($service)] ?? self::CHAINS['SHARED']);
    }

    public function firstStep(string $service): array
    {
        return (self::CHAINS[strtoupper($service)] ?? self::CHAINS['SHARED'])[0];
    }

    /**
     * @return array{0:string,1:string,2:string,3:string}|null
     */
    public function nextStep(string $service, int $currentIndex): ?array
    {
        $chain = self::CHAINS[strtoupper($service)] ?? self::CHAINS['SHARED'];
        return $chain[$currentIndex + 1] ?? null;
    }

    public function stepIndex(string $service, string $stepCode): int
    {
        $chain = self::CHAINS[strtoupper($service)] ?? self::CHAINS['SHARED'];
        foreach ($chain as $i => $step) {
            if ($step[0] === $stepCode) {
                return $i;
            }
        }
        return 0;
    }
}