<?php
// src/Service/TicketWorkflowService.php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\TicketTask;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class TicketWorkflowService
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationService $notificationService,
        private UserRepository $userRepository,
        private WorkflowStepChain $stepChain,
    ) {}

    public function canAccessTicket(Ticket $ticket, User $user): bool
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_SUPERUSER', $roles, true)) {
            return true;
        }
        if ($ticket->getCreatedBy()?->getId() === $user->getId()) {
            return true;
        }
        foreach ($ticket->getTasks() as $task) {
            if ($task->getAssignedTo()?->getId() === $user->getId()) {
                return true;
            }
            if ($user->getService() && $task->getServiceName() === $user->getService()) {
                return true;
            }
        }
        return false;
    }

    public function canActOnTask(TicketTask $task, User $user): bool
    {
        if ($task->getStatus() === TicketTask::STATUS_DONE) {
            return false;
        }
        if ($task->getAssignedTo()?->getId() === $user->getId()) {
            return true;
        }
        return $user->getService() !== null && $task->getServiceName() === $user->getService();
    }

    public function addHistory(Ticket $ticket, ?User $user, string $action, ?string $details = null, ?string $site = null): TicketHistory
    {
        $history = new TicketHistory();
        $history->setTicket($ticket);
        $history->setUser($user);
        $history->setAction($action);
        $history->setDetails($details);
        $history->setSite($site ?? $ticket->getSiteName() ?? null);
        $history->setDateJour(new \DateTime());
        $this->em->persist($history);
        $this->em->flush();
        return $history;
    }

    /**
     * Create the next task in a site's workflow while keeping its context.
     */
    public function moveToNextTask(TicketTask $currentTask, User $nextUser, string $nextStepCode): TicketTask
    {
        $nextTask = new TicketTask();
        $nextTask->setTicket($currentTask->getTicket());
        $nextTask->setTicketSite($currentTask->getTicketSite());
        $nextTask->setTitle('Suite du workflow');
        $nextTask->setDescription($currentTask->getDescription());
        $nextTask->setAssignedTo($nextUser);
        $nextTask->setStatus(TicketTask::STATUS_PENDING);
        $nextTask->setStepOrder($currentTask->getStepOrder() + 1);
        $nextTask->setStepCode($nextStepCode);
        $nextTask->setServiceName($nextUser->getService());

        $ticketSite = $currentTask->getTicketSite();
        if ($ticketSite) {
            $service = $ticketSite->getServiceName() ?: 'SHARED';
            $nextIndex = $this->stepChain->stepIndex($service, $nextStepCode);

            // Some legacy transitions (for example FH hard -> deployment)
            // are not named in the generic chain. They still advance this
            // site's progress by one step rather than resetting it to zero.
            if ($nextIndex === 0 && $ticketSite->getCurrentStepCode() !== $nextStepCode) {
                $nextIndex = $ticketSite->getCurrentStepIndex() + 1;
            }

            $ticketSite->setTotalSteps(max(
                $ticketSite->getTotalSteps(),
                $this->stepChain->totalStepsFor($service)
            ));
            $ticketSite->setCurrentStepIndex(min($nextIndex, $ticketSite->getTotalSteps() - 1));
            $ticketSite->setCurrentStepCode($nextStepCode);
            $ticketSite->setStatus('in_progress');
        }

        $this->em->persist($nextTask);

        return $nextTask;
    }

    private function requestSuperuserValidation(Ticket $ticket): void
    {
        $notifiedLevels = $ticket->getNotifiedLevels() ?? [];
        if (!empty($notifiedLevels['superuser_validation_requested'])) {
            return;
        }
        $ticket->setNotifiedLevels(array_merge($notifiedLevels, [
            'superuser_validation_requested' => (new \DateTime())->format(DATE_ATOM),
        ]));
        $this->notificationService->notifyWorkflowReadyForSuperuser($ticket);
        $this->addHistory($ticket, null, 'workflow_ready_for_validation', 'Workflow terminé à 100% en attente de validation superuser.');
    }

    /**
     * La progression du WORKFLOW (Ticket) est la MOYENNE des progressions
     * de chaque SITE individuel, chaque site avançant dans la chaîne
     * propre à son service (FO / FH / SHARED...). Ne dépend plus d'un
     * compteur global currentStep/totalSteps qui ne peut pas représenter
     * plusieurs chaînes parallèles.
     */
    public function refreshTicketProgress(Ticket $ticket): void
    {
        $sites = $ticket->getTicketSites();
        $total = $sites->count();

        if ($total === 0) {
            $ticket->setProgress(0);
            return;
        }

        $sumPercent = 0;
        $allTerminal = true;
        $anyStarted = false;
        $activeSiteIds = $this->activeSiteIds($ticket);

        foreach ($sites as $site) {
            $siteHasActiveTask = isset($activeSiteIds[$site->getId()]);
            $siteIsTerminal = in_array($site->getStatus(), ['completed', 'validated', 'rejected'], true)
                && !$siteHasActiveTask;

            $siteProgress = $siteIsTerminal
                ? 100
                : (int) round(($site->getCurrentStepIndex() / max(1, $site->getTotalSteps())) * 100);
            $sumPercent += $siteProgress;

            if (!$siteIsTerminal) {
                $allTerminal = false;
            }
            if ($site->getStatus() !== 'pending' || $siteHasActiveTask) {
                $anyStarted = true;
            }
        }

        $progress = (int) round($sumPercent / $total);
        $ticket->setProgress(min(100, $progress));

        // Si tous les sites sont terminaux
        if ($allTerminal) {
            // Vérifier si tous les sites sont validés ou rejetés
            $allValidatedOrRejected = true;
            foreach ($sites as $site) {
                if (!in_array($site->getStatus(), ['validated', 'rejected'])) {
                    $allValidatedOrRejected = false;
                    break;
                }
            }
            if ($allValidatedOrRejected) {
                if (!in_array($ticket->getStatus(), ['closed', 'completed'])) {
                    $ticket->setStatus('completed');
                    $ticket->setUpdatedAt(new \DateTime());
                    $this->addHistory($ticket, null, 'ticket_completed', 'Tous les sites sont validés ou rejetés.');
                }
                return;
            }

            // Sinon, il y a des sites terminaux mais pas encore validés : on passe en waiting_superuser
            if (!in_array($ticket->getStatus(), ['closed', 'waiting_superuser', 'completed'], true)) {
                $ticket->setStatus('waiting_superuser');
                $ticket->setUpdatedAt(new \DateTime());
                $this->requestSuperuserValidation($ticket);
            }
            return;
        }

        // Sinon, le ticket est en cours
        if ($anyStarted && !in_array($ticket->getStatus(), ['closed', 'waiting_superuser', 'completed'], true)) {
            $ticket->setStatus('in_progress');
            $ticket->setUpdatedAt(new \DateTime());
        }
    }

    /**
     * Retourne les IDs des sites qui ont au moins une tâche en attente ou en cours.
     * @return array<int, true>
     */
    private function activeSiteIds(Ticket $ticket): array
    {
        $activeSiteIds = [];

        foreach ($ticket->getTasks() as $task) {
            if (!in_array($task->getStatus(), [TicketTask::STATUS_PENDING, TicketTask::STATUS_IN_PROGRESS], true)) {
                continue;
            }

            if ($task->getTicketSite()?->getId() !== null) {
                $activeSiteIds[$task->getTicketSite()->getId()] = true;
            }

            // Compatibility with tasks created before TicketTask::ticketSite.
            foreach ($task->getSiteData() ?? [] as $siteId) {
                if (is_int($siteId) || (is_string($siteId) && ctype_digit($siteId))) {
                    $activeSiteIds[(int) $siteId] = true;
                }
            }
        }

        return $activeSiteIds;
    }
}