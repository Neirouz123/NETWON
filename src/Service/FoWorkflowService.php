<?php
// src/Service/FoWorkflowService.php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class FoWorkflowService
{
    private const STEP_FLOW = [
        TicketTask::STEP_FO_INITIAL_ANALYSIS => [
            'next' => TicketTask::STEP_FO_DEPLOYMENT_PLANNING,
            'title' => 'Planification intervention',
            'service' => 'DEPLOIEMENT',
            'department' => 'deploiement_telecom',
        ],
        TicketTask::STEP_FO_DEPLOYMENT_PLANNING => [
            'next' => TicketTask::STEP_FO_SITE_EXECUTION,
            'title' => 'Exécution sur site',
            'service' => 'DEPLOIEMENT',
            'department' => 'deploiement_telecom',
        ],
        TicketTask::STEP_FO_SITE_EXECUTION => [
            'next' => null,
            'title' => 'Validation Superuser',
            'service' => 'SUPERUSER',
            'department' => null,
        ],
        TicketTask::STEP_FO_WO_IP_CREATION => [
            'next' => TicketTask::STEP_FO_DEPLOYMENT_PLANNING,
            'title' => 'Création WO IP',
            'service' => 'FO',
            'department' => 'ingenierie_ip',
        ],
        TicketTask::STEP_FO_IP_SWAP_ANALYSIS => [
            'next' => TicketTask::STEP_FO_WO_IP_CREATION,
            'title' => 'Swap routeur',
            'service' => 'FO',
            'department' => 'ingenierie_ip',
        ],
    ];

    public const MOTIFS = [
        'besoin_2eme_paire'   => 'Besoin 2ème paire FO',
        'besoin_swap_routeur' => 'Besoin swap routeur',
        'autre'               => 'Autre action (upgrade équipement, etc.)',
    ];

    private const SHARED_DEPARTMENTS = [
        'deploiement_telecom',
        'support_radio',
        'support_backhaul',
    ];

     public function __construct(
        private EntityManagerInterface $em,
        private TicketWorkflowService $workflowService,
        private UserRepository $userRepo,
        private LoggerInterface $logger,
        private NotificationService $notificationService,
        private FhWorkflowService $fhWorkflowService,
    ) {}


    /**
     * Traite la décision pour un site spécifique dans l'analyse initiale.
     */
    public function processSiteDecision(TicketTask $task, int $siteId, string $decision, ?string $motif, User $actor): void
    {
        $ticket = $task->getTicket();
        $this->logger->info('FoWorkflowService: processing site decision', [
            'task_id' => $task->getId(),
            'site_id' => $siteId,
            'decision' => $decision,
            'motif' => $motif,
        ]);

        $nextStep = null;
        $service = null;
        $department = null;
        $title = null;

        if ($decision === 'OK') {
            $nextStep = TicketTask::STEP_FO_DEPLOYMENT_PLANNING;
            $service = 'DEPLOIEMENT';
            $department = 'deploiement_telecom';
            $title = 'Planification intervention';
        } elseif ($decision === 'NOK' && $motif === 'besoin_2eme_paire') {
            $nextStep = TicketTask::STEP_FO_CAPILLAIRE_STUDY;
            $service = 'FH';
            $department = 'ingenierie_capillaire';
            $title = 'Raccordement FO (2ème paire)';
        } elseif ($decision === 'NOK' && $motif === 'besoin_swap_routeur') {
            $nextStep = TicketTask::STEP_FO_IP_SWAP_ANALYSIS;
            $service = 'FO';
            $department = 'ingenierie_ip';
            $title = 'Swap routeur';
        } else {
            // Autre cas : bloquer le site
            foreach ($ticket->getTicketSites() as $ts) {
                if ($ts->getId() === $siteId) {
                    $ts->setStatus('blocked');
                    break;
                }
            }
            $this->workflowService->addHistory($ticket, $actor, 'site_blocked', 'Site #' . $siteId . ' bloqué pour motif: ' . ($motif ?? 'inconnu'));
            $this->em->flush();
            return;
        }

        // Créer une nouvelle tâche pour ce site uniquement
        $nextUser = $this->pickUser($service, $department, $actor);
        $ticketSite = null;
        foreach ($ticket->getTicketSites() as $site) {
            if ($site->getId() === $siteId) {
                $ticketSite = $site;
                break;
            }
        }

        $newTask = new TicketTask();
        $newTask->setTicket($ticket);
        $newTask->setTicketSite($ticketSite);
        $newTask->setAssignedTo($nextUser);
        $newTask->setTitle($title);
        $newTask->setServiceName($service);
        $newTask->setDepartmentName($department);
        $newTask->setStatus(TicketTask::STATUS_PENDING);
        $newTask->setStepCode($nextStep);
        $newTask->setStepOrder($task->getStepOrder() + 1);
        $newTask->setSiteData([$siteId]); // Un seul site
        $newTask->setFhFields($task->getFhFields());

        if ($nextStep === TicketTask::STEP_FO_DEPLOYMENT_PLANNING) {
            $newTask->setDeploiementData(['workflow_origin' => 'fo']);
        }

        if ($task->getWoIpContent()) {
            $newTask->setWoIpContent($task->getWoIpContent());
        }

        $this->em->persist($newTask);

        // Marquer le site comme traité dans TicketSite
        foreach ($ticket->getTicketSites() as $ts) {
            if ($ts->getId() === $siteId) {
                $ts->setStatus('in_progress');
                break;
            }
        }

        $siteDecisions = $task->getSiteDecisions() ?? [];
        $siteDecisions[$siteId] = [
            'decision' => $decision,
            'motif' => $motif,
        ];
        $task->setSiteDecisions($siteDecisions);

        // Vérifier si tous les sites de la tâche sont traités
        $allDone = true;
        foreach ($ticket->getTicketSites() as $ts) {
            if (!isset($siteDecisions[$ts->getId()])) {
                $allDone = false;
                break;
            }
        }

        if ($allDone) {
            $task->setStatus(TicketTask::STATUS_DONE);
            $task->setCompletedAt(new \DateTime());
            $this->workflowService->addHistory($ticket, $actor, 'task_completed', 'Tous les sites traités');
        } else {
            $task->setStatus(TicketTask::STATUS_IN_PROGRESS);
            $this->workflowService->addHistory($ticket, $actor, 'site_processed', 'Site #' . $siteId . ' traité');
        }

        $this->em->flush();
        $this->workflowService->refreshTicketProgress($ticket);

        $this->notificationService->notify(
            $nextUser,
            'task_assigned',
            'Nouvelle tâche : ' . $title . ' pour le ticket #' . $ticket->getId() . ' (site ' . $siteId . ')',
            $ticket
        );

        $this->logger->info('FoWorkflowService: site processed and new task created', [
            'new_task_id' => $newTask->getId(),
            'assigned_to' => $nextUser->getUserIdentifier(),
        ]);
    }

    /**
     * Traite une tâche complète (pour les étapes suivantes).
     */
    public function completeFoTask(TicketTask $task, string $decision, ?string $motif, User $actor): void
    {
        $this->logger->info('FoWorkflowService: processing task (legacy)', [
            'task_id' => $task->getId(),
            'step' => $task->getStepCode(),
            'decision' => $decision,
        ]);

        $task->setStatus(TicketTask::STATUS_DONE);
        $task->setCompletedAt(new \DateTime());
        $ticket = $task->getTicket();
        $stepCode = $task->getStepCode();

        $woIpContent = $task->getWoIpContent();
        $nextStep = null;

        if ($stepCode === TicketTask::STEP_FO_DEPLOYMENT_PLANNING) {
            // ... gestion existante ...
        } elseif ($stepCode === TicketTask::STEP_FO_SITE_EXECUTION) {
            // ... gestion existante ...
        } elseif ($stepCode === TicketTask::STEP_FO_WO_IP_CREATION) {
            // ... gestion existante ...
        } elseif ($stepCode === TicketTask::STEP_FO_IP_SWAP_ANALYSIS) {
            // ... gestion existante ...
        } elseif ($stepCode === TicketTask::STEP_FO_CAPILLAIRE_STUDY) {
            // NOUVELLE LOGIQUE : décision d'Ingénierie Capillaire
            if ($decision === 'OK') {
                // OK → Planification (Déploiement)
                $nextStep = TicketTask::STEP_FO_DEPLOYMENT_PLANNING;
                $flowDef = self::STEP_FLOW[$nextStep] ?? null;
                if (!$flowDef) {
                    throw new \RuntimeException("Étape suivante non définie : $nextStep");
                }
                $nextUser = $this->pickUser($flowDef['service'], $flowDef['department'], $actor);
                $newTask = $this->workflowService->moveToNextTask($task, $nextUser, $nextStep);
                $newTask->setTitle($flowDef['title']);
                $newTask->setServiceName($flowDef['service']);
                $newTask->setDepartmentName($flowDef['department']);
                $newTask->setSiteData($task->getSiteData());
                $newTask->setFhFields($task->getFhFields());

                $ticket->setCurrentStep($ticket->getCurrentStep() + 1);
                $ticket->setUpdatedAt(new \DateTime());
                $this->workflowService->refreshTicketProgress($ticket);
                $this->workflowService->addHistory(
                    $ticket,
                    $actor,
                    'task_transferred',
                    sprintf('Étape "Planification intervention" transmise à %s (Déploiement).', $nextUser->getUserIdentifier())
                );
                $this->em->flush();
                return;
            } elseif ($decision === 'NOK') {
                // NOK → Retour à Ingénierie IP (ré-analyse initiale)
                $nextStep = TicketTask::STEP_FO_INITIAL_ANALYSIS;
                $nextUser = $this->pickUser('FO', 'ingenierie_ip', $actor);
                $newTask = $this->workflowService->moveToNextTask($task, $nextUser, $nextStep);
                $newTask->setTitle('Étude initiale FO (reprise)');
                $newTask->setDescription('Ré-analyser la demande suite à l\'échec de l\'étude capillaire.');
                $newTask->setServiceName('FO');
                $newTask->setDepartmentName('ingenierie_ip');
                $newTask->setSiteData($task->getSiteData());
                $newTask->setFhFields($task->getFhFields());

                $ticket->setCurrentStep($ticket->getCurrentStep() + 1);
                $ticket->setUpdatedAt(new \DateTime());
                $this->workflowService->refreshTicketProgress($ticket);
                $this->workflowService->addHistory(
                    $ticket,
                    $actor,
                    'task_transferred',
                    sprintf('Étape "Étude initiale FO (reprise)" transmise à %s (IP).', $nextUser->getUserIdentifier())
                );
                $this->em->flush();
                return;
            } else {
                // Autre décision → bloquer
                $ticket->setStatus('blocked');
                $this->workflowService->addHistory($ticket, $actor, 'site_blocked', 'Décision inconnue pour l\'étude capillaire.');
                $this->em->flush();
                return;
            }
        } else {
            // Autres étapes (existantes)
            $flowDef = self::STEP_FLOW[$stepCode] ?? null;
            if ($flowDef && $flowDef['next']) {
                $nextStep = $flowDef['next'];
            } else {
                $nextStep = null;
            }
        }

        // Si aucune étape suivante, création validation superuser (comportement existant)
        if (!$nextStep) {
            $this->createSuperuserValidationTask($ticket, $task);
            $ticket->setCurrentStep($ticket->getTotalSteps());
            $this->workflowService->refreshTicketProgress($ticket);
            $this->workflowService->addHistory($ticket, $actor, 'workflow_completed', 'Workflow terminé, en attente de validation superuser.');
            $this->em->flush();
            return;
        }

        // Transitions standards
        $flowDef = self::STEP_FLOW[$nextStep] ?? null;
        if (!$flowDef) {
            throw new \RuntimeException("Étape suivante non définie : $nextStep");
        }

        $nextUser = $this->pickUser($flowDef['service'], $flowDef['department'], $actor);
        $newTask = $this->workflowService->moveToNextTask($task, $nextUser, $nextStep);
        $newTask->setTitle($flowDef['title']);
        $newTask->setServiceName($flowDef['service']);
        $newTask->setDepartmentName($flowDef['department']);
        $newTask->setSiteData($task->getSiteData());

        if (!empty($woIpContent)) {
            $newTask->setWoIpContent($woIpContent);
        }

        $ticket->setCurrentStep($ticket->getCurrentStep() + 1);
        $ticket->setUpdatedAt(new \DateTime());

        $this->workflowService->refreshTicketProgress($ticket);
        $this->workflowService->addHistory(
            $ticket,
            $actor,
            'task_transferred',
            sprintf('Étape "%s" transmise à %s (%s).', $flowDef['title'], $nextUser->getUserIdentifier(), $flowDef['service'])
        );

        $this->em->flush();
    }



    private function getSitesForTask(TicketTask $task): array
    {
        if ($task->getTicketSite()) {
            return [$task->getTicketSite()];
        }
        $ticket = $task->getTicket();
        if (!$ticket) {
            return [];
        }
        $siteIds = $task->getSiteData() ?? [];
        if (empty($siteIds)) {
            return $ticket->getTicketSites()->toArray();
        }
        return array_values(array_filter(
            $ticket->getTicketSites()->toArray(),
            fn($site) => in_array($site->getId(), $siteIds, true)
        ));
    }

     public function createSuperuserValidationTask(Ticket $ticket, TicketTask $currentTask): void
    {
        $superusers = $this->userRepo->findUsersByRole('ROLE_SUPERUSER');
        if (empty($superusers)) {
            $this->logger->error('Aucun superuser trouvé pour la validation finale.');
            return;
        }

        // Récupérer les sites concernés
        $sites = $this->getSitesForTask($currentTask);

        foreach ($sites as $site) {
            $superuser = $superusers[array_rand($superusers)];
            $task = new TicketTask();
            $task->setTicket($ticket);
            $task->setTicketSite($site);
            $task->setTitle('Validation finale — ' . $site->getSiteName());
            $task->setDescription('Vérifier les KPI et valider le site.');
            $task->setAssignedTo($superuser);
            $task->setServiceName('SUPERUSER');
            $task->setDepartmentName(null);
            $task->setStatus(TicketTask::STATUS_PENDING);
            $task->setStepCode(TicketTask::STEP_SUPERUSER_VALIDATION);
            $task->setStepOrder($currentTask->getStepOrder() + 1);
            $task->setSiteData([$site->getId()]);
            $task->setFhFields($currentTask->getFhFields());

            $this->em->persist($task);
            $this->notificationService->notify(
                $superuser,
                'task_assigned',
                'Validation site ' . $site->getSiteName() . ' pour le ticket #' . $ticket->getId(),
                $ticket
            );
        }

        $this->em->flush();
    }


    private function pickUser(string $service, ?string $department, User $fallbackUser): User
    {
        if ($service === 'SUPERUSER') {
            $superusers = $this->userRepo->findUsersByRole('ROLE_SUPERUSER');
            if (!empty($superusers)) {
                return $superusers[0];
            }
            return $fallbackUser;
        }

        if ($department !== null && in_array($department, self::SHARED_DEPARTMENTS, true)) {
            $users = $this->userRepo->findBy(['department' => $department]);
            foreach ($users as $u) {
                if (in_array('ROLE_USER', $u->getRoles(), true)) {
                    return $u;
                }
            }
        }

        if ($department !== null) {
            $users = $this->userRepo->findBy(['service' => $service, 'department' => $department]);
            foreach ($users as $u) {
                if (in_array('ROLE_USER', $u->getRoles(), true)) {
                    return $u;
                }
            }
        }

        if ($department !== null && in_array($department, self::SHARED_DEPARTMENTS, true)) {
            $otherService = ($service === 'FO') ? 'FH' : 'FO';
            $users = $this->userRepo->findBy(['service' => $otherService, 'department' => $department]);
            foreach ($users as $u) {
                if (in_array('ROLE_USER', $u->getRoles(), true)) {
                    return $u;
                }
            }
        }

        $users = $this->userRepo->findBy(['service' => $service]);
        foreach ($users as $u) {
            if (in_array('ROLE_USER', $u->getRoles(), true)) {
                return $u;
            }
        }

        $users = $this->userRepo->findBy(['service' => 'SHARED']);
        foreach ($users as $u) {
            if (in_array('ROLE_USER', $u->getRoles(), true)) {
                return $u;
            }
        }

        return $fallbackUser;
    }

    public function getStepTitle(string $stepCode): string
    {
        return self::STEP_FLOW[$stepCode]['title'] ?? $stepCode;
    }


    // Nouvelle méthode :
/**
 * Les étapes FH affichées dans le dashboard FO (fh_execution_wo côté
 * support_trans, fh_lld côté ingenierie_ip) sont réellement pilotées par
 * FhWorkflowService — ce dashboard n'est qu'une vue partagée pour ces
 * deux départements.
 */
public function completeFhTaskViaFoDashboard(TicketTask $task, string $decision, array $formData, ?string $comment, User $actor): void
{
    if ($comment) {
        $formData['comment'] = $comment;
    }
    $this->fhWorkflowService->processFhTask($task, $decision, $formData, $actor);
}
}
