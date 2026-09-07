<?php
// src/Service/FhWorkflowService.php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketSite;
use App\Entity\TicketTask;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Chaîne FH complète :
 *
 *  fh_etude_prerequis (ingenierie_capillaire)
 *      -> fh_maj_capacite (ingenierie_capillaire)
 *          - capacité OK / soft upgrade -> fh_execution_wo (support_trans) [TERMINAL]
 *          - hard upgrade              -> fh_mlo (deploiement_telecom)
 *              - MLO OK  -> fh_validation_capillaire (ingenierie_capillaire) [TERMINAL]
 *              - MLO NOK -> fh_lld (ingenierie_ip)
 *                              -> fh_mlo_validation (deploiement_telecom) [TERMINAL]
 */
class FhWorkflowService
{
    private const TOTAL_STEPS_SOFT = 3;
    private const TOTAL_STEPS_HARD_MLO_OK = 4;
    private const TOTAL_STEPS_HARD_MLO_NOK = 5;

    public function __construct(
        private EntityManagerInterface $em,
        private TicketWorkflowService $workflowService,
        private UserRepository $userRepo,
        private NotificationService $notificationService,
        private LoggerInterface $logger,
    ) {}

    public function processFhTask(TicketTask $task, string $decision, ?array $formData, User $actor): void
    {
        $stepCode = $task->getStepCode();
        $ticket = $task->getTicket();
        $ticketSite = $task->getTicketSite();

        $this->finishCurrentTask($task, $formData, $actor);

        match ($stepCode) {
            TicketTask::STEP_FH_ETUDE_PREREQUIS => $this->afterEtudePrerequis($task, $ticket, $ticketSite, $actor),
            TicketTask::STEP_FH_MAJ_CAPACITE => $this->afterMajCapacite($task, $ticket, $ticketSite, $formData, $actor),
            TicketTask::STEP_FH_EXECUTION_WO => $this->completeSiteTerminal($ticket, $ticketSite, $actor, 'Exécution WO effectuée (Support Trans).'),
            TicketTask::STEP_FH_VALIDATION_CAPILLAIRE => $this->completeSiteTerminal($ticket, $ticketSite, $actor, 'Site validé par Ingénierie Capillaire après MLO OK.'),
            TicketTask::STEP_FH_LLD => $this->afterLld($task, $ticket, $ticketSite, $actor),
            default => $this->logger->warning('FhWorkflowService: étape FH inconnue', ['step' => $stepCode]),
        };

        $this->workflowService->refreshTicketProgress($ticket);
        $this->em->flush();
    }

    public function processMloDecision(TicketTask $task, string $mloDecision, ?string $comment, User $actor): void
    {
        $stepCode = $task->getStepCode();
        $ticket = $task->getTicket();
        $ticketSite = $task->getTicketSite();

        $task->setDecision($mloDecision);
        $task->setComment($comment);
        $task->setStatus(TicketTask::STATUS_DONE);
        $task->setCompletedAt(new \DateTime());
        $task->setUpdatedAt(new \DateTime());

        $isOk = strtoupper($mloDecision) === 'OK';

        if ($stepCode === TicketTask::STEP_FH_MLO) {
            if ($isOk) {
                $this->advanceTo($ticket, $ticketSite, $task, TicketTask::STEP_FH_VALIDATION_CAPILLAIRE,
                    'Validation finale (MLO OK)', 'ingenierie_capillaire', self::TOTAL_STEPS_HARD_MLO_OK,
                    'MLO OK, tâche transmise à Ingénierie Capillaire pour validation finale.', $actor);
            } else {
                $this->advanceTo($ticket, $ticketSite, $task, TicketTask::STEP_FH_LLD,
                    'LLD Port Routeur (MLO NOK)', 'ingenierie_ip', self::TOTAL_STEPS_HARD_MLO_NOK,
                    'MLO NOK, tâche transmise à Ingénierie IP pour le LLD port routeur.', $actor);
            }
        } elseif ($stepCode === TicketTask::STEP_FH_MLO_VALIDATION) {
            $this->completeSiteTerminal($ticket, $ticketSite, $actor, 'MLO validé par Déploiement après LLD (Ingénierie IP).');
        } else {
            $this->logger->warning('FhWorkflowService::processMloDecision: étape inattendue', ['step' => $stepCode]);
        }

        $this->workflowService->refreshTicketProgress($ticket);
        $this->em->flush();
    }

    // ==================== Transitions internes ====================

    private function afterEtudePrerequis(TicketTask $task, Ticket $ticket, ?TicketSite $ticketSite, User $actor): void
    {
        $this->advanceTo($ticket, $ticketSite, $task, TicketTask::STEP_FH_MAJ_CAPACITE,
            'MAJ Capacité', 'ingenierie_capillaire', self::TOTAL_STEPS_SOFT,
            'Étude des prérequis terminée, transmise pour MAJ Capacité.', $actor);
    }

    /**
     * CORRECTION : on lit 'capacite_ok' depuis les champs FH enregistrés (étape précédente)
     * et 'type_upgrade' depuis le formulaire actuel.
     */
    private function afterMajCapacite(TicketTask $task, Ticket $ticket, ?TicketSite $ticketSite, ?array $formData, User $actor): void
    {
        $fhFields = $task->getFhFields() ?? [];
        $capaciteOk = strtoupper((string) ($fhFields['capacite_ok'] ?? 'OK')) === 'OK';
        $upgradeType = strtolower((string) ($formData['type_upgrade'] ?? ''));

        if ($capaciteOk || $upgradeType === '' || $upgradeType === 'soft') {
            // Capacité OK, ou upgrade soft : Support Trans exécute le WO IP.
            $this->advanceTo($ticket, $ticketSite, $task, TicketTask::STEP_FH_EXECUTION_WO,
                'Exécution WO IP', 'support_trans', self::TOTAL_STEPS_SOFT,
                'Capacité OK / upgrade soft, transmis à Support Trans pour exécution WO IP.', $actor);
            return;
        }

        // Upgrade hard : Déploiement doit d'abord valider le MLO.
        $this->advanceTo($ticket, $ticketSite, $task, TicketTask::STEP_FH_MLO,
            'MLO (Déploiement Télécom)', 'deploiement_telecom', self::TOTAL_STEPS_HARD_MLO_OK,
            'Upgrade hard demandé, transmis à Déploiement pour validation MLO.', $actor);
    }

    private function afterLld(TicketTask $task, Ticket $ticket, ?TicketSite $ticketSite, User $actor): void
    {
        $this->advanceTo($ticket, $ticketSite, $task, TicketTask::STEP_FH_MLO_VALIDATION,
            'Validation MLO après LLD', 'deploiement_telecom', self::TOTAL_STEPS_HARD_MLO_NOK,
            'LLD port routeur renseigné, retour à Déploiement pour valider le MLO.', $actor);
    }

    private function completeSiteTerminal(Ticket $ticket, ?TicketSite $ticketSite, User $actor, string $message): void
    {
        if ($ticketSite) {
            $ticketSite->setStatus(TicketSite::STATUS_COMPLETED);
            $ticketSite->setCurrentStepIndex($ticketSite->getTotalSteps());
            // Créer une tâche de validation superuser pour ce site
            $this->createSuperuserValidationForSite($ticket, $ticketSite, $actor);
        }
        $this->workflowService->addHistory($ticket, $actor, 'site_completed', $message, $ticketSite?->getSiteName());
    }


    private function finishCurrentTask(TicketTask $task, ?array $formData, User $actor): void
    {
        $task->setStatus(TicketTask::STATUS_DONE);
        $task->setCompletedAt(new \DateTime());
        $task->setUpdatedAt(new \DateTime());
        if ($formData) {
            $task->setFhFields(array_merge($task->getFhFields() ?? [], $formData));
        }
    }

    private function advanceTo(
        Ticket $ticket,
        ?TicketSite $ticketSite,
        TicketTask $currentTask,
        string $nextStepCode,
        string $title,
        string $department,
        int $totalStepsForBranch,
        string $historyMessage,
        User $actor
    ): void {
        $nextUser = $this->pickUserForDepartment($department);

        $newTask = new TicketTask();
        $newTask->setTicket($ticket);
        $newTask->setTicketSite($ticketSite);
        $newTask->setAssignedTo($nextUser);
        $newTask->setTitle($title . ($ticketSite ? ' — ' . $ticketSite->getSiteName() : ''));
        $newTask->setDescription($title);
        $newTask->setServiceName('FH');
        $newTask->setDepartmentName($department);
        $newTask->setStepCode($nextStepCode);
        $newTask->setStepOrder($currentTask->getStepOrder() + 1);
        $newTask->setStatus(TicketTask::STATUS_PENDING);
        $newTask->setSiteData($currentTask->getSiteData());
        $newTask->setFhFields($currentTask->getFhFields());
        $newTask->setCreatedAt(new \DateTime());
        $newTask->setUpdatedAt(new \DateTime());

        $this->em->persist($newTask);

        if ($ticketSite) {
            $ticketSite->setTotalSteps($totalStepsForBranch);
            $ticketSite->setCurrentStepIndex(min(
                $ticketSite->getCurrentStepIndex() + 1,
                $totalStepsForBranch - 1
            ));
            $ticketSite->setCurrentStepCode($nextStepCode);
            $ticketSite->setStatus(TicketSite::STATUS_IN_PROGRESS);
        }

        $ticket->setUpdatedAt(new \DateTime());

        $this->workflowService->addHistory($ticket, $actor, 'task_transferred', $historyMessage, $ticketSite?->getSiteName());

        $this->notificationService->notify(
            $nextUser,
            NotificationService::TYPE_WORKFLOW_ASSIGNED,
            sprintf('Nouvelle tâche : %s pour le ticket #%d - %s', $title, $ticket->getId() ?? 0, $ticket->getTitle()),
            $ticket
        );
    }

    private function pickUserForDepartment(string $department): User
    {
        $users = array_values(array_filter(
            $this->userRepo->findBy(['department' => $department]),
            fn(User $u) => in_array('ROLE_USER', $u->getRoles(), true)
        ));

        if (!empty($users)) {
            return $users[array_rand($users)];
        }

        $superusers = $this->userRepo->findUsersByRole('ROLE_SUPERUSER');
        if (!empty($superusers)) {
            $this->logger->warning(
                'Aucun utilisateur trouvé pour le département {dept}, fallback sur un superuser.',
                ['dept' => $department]
            );
            return $superusers[0];
        }

        throw new \RuntimeException(sprintf(
            'Impossible de trouver un utilisateur pour le département "%s". Créez un utilisateur avec ce département ou un superuser.',
            $department
        ));
    }


     private function createSuperuserValidationForSite(Ticket $ticket, TicketSite $ticketSite, User $actor): void
    {
        $superusers = $this->userRepo->findUsersByRole('ROLE_SUPERUSER');
        if (empty($superusers)) {
            $this->logger->error('Aucun superuser trouvé pour la validation du site ' . $ticketSite->getSiteName());
            return;
        }
        $superuser = $superusers[0]; // ou round-robin

        $task = new TicketTask();
        $task->setTicket($ticket);
        $task->setTicketSite($ticketSite);
        $task->setTitle('Validation superuser — ' . $ticketSite->getSiteName());
        $task->setDescription('Vérifier la conformité du site et valider.');
        $task->setAssignedTo($superuser);
        $task->setServiceName('SUPERUSER');
        $task->setDepartmentName(null);
        $task->setStatus(TicketTask::STATUS_PENDING);
        $task->setStepCode(TicketTask::STEP_SUPERUSER_VALIDATION);
        $task->setStepOrder(999); // à adapter selon le contexte
        $task->setCreatedAt(new \DateTime());
        $task->setUpdatedAt(new \DateTime());

        $this->em->persist($task);
        $this->notificationService->notify(
            $superuser,
            NotificationService::TYPE_WORKFLOW_ASSIGNED,
            'Site ' . $ticketSite->getSiteName() . ' en attente de validation (ticket #' . $ticket->getId() . ')',
            $ticket
        );
    }
}