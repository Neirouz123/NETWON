<?php
// src/Service/WorkflowEngineService.php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\TicketSite;
use App\Entity\TicketTask;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class WorkflowEngineService
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationService $notificationService,
        private TicketWorkflowService $ticketWorkflowService,
        private WorkflowStepChain $stepChain
    ) {}

    public function startTask(TicketTask $task, User $user): void
    {
        $task->setStatus(TicketTask::STATUS_IN_PROGRESS);
        $task->setStartedAt(new \DateTime());
        $task->setUpdatedAt(new \DateTime());

        $this->addHistory(
            $task->getTicket(),
            $user,
            'task_started',
            'La tâche #' . $task->getId() . ' a été démarrée par ' . $user->getUsername(),
            $task->getTicketSite()?->getSiteName()
        );

        $this->em->flush();
    }

    /**
     * Choix génériques proposés à l'utilisateur. La chaîne d'étapes étant
     * déjà déterministe par service (voir WorkflowStepChain), on n'a plus
     * besoin de décisions à embranchements multiples câblées en dur.
     */
    public function choicesFor(TicketTask $task): array
    {
        return [
            '✅ OK - Action réalisée avec succès' => 'ok',
            '❌ NOK - Action non réalisée / bloquée' => 'nok',
        ];
    }

    /**
     * Termine la tâche courante du user sur SON site, et fait avancer
     * UNIQUEMENT ce site vers l'étape suivante de SA chaîne de service.
     * Les autres sites du même workflow ne sont pas impactés.
     */
    public function completeTaskForSite(
        TicketTask $task,
        User $user,
        string $decision,
        ?string $comment = null,
        ?string $proofFile = null
    ): void {
        $ticket = $task->getTicket();
        $ticketSite = $task->getTicketSite();

        $task->setDecision($decision);
        $task->setComment($comment);
        $task->setProofFile($proofFile);
        $task->setStatus(TicketTask::STATUS_DONE);
        $task->setCompletedAt(new \DateTime());
        $task->setUpdatedAt(new \DateTime());

        $this->addHistory(
            $ticket,
            $user,
            'task_completed',
            sprintf('Tâche terminée. Décision: %s.%s', $decision, $comment ? ' Commentaire: ' . $comment : ''),
            $ticketSite?->getSiteName()
        );

        if ($ticketSite) {
            if ($decision === 'nok') {
                $ticketSite->setStatus(TicketSite::STATUS_REJECTED);
                $this->addHistory($ticket, $user, 'site_rejected', 'Site rejeté (NOK).', $ticketSite->getSiteName());
            } else {
                $this->advanceSite($ticket, $ticketSite);
            }
        }

        $this->ticketWorkflowService->refreshTicketProgress($ticket);
        $this->em->flush();
    }

    private function advanceSite(Ticket $ticket, TicketSite $ticketSite): void
    {
        $service = strtoupper($ticketSite->getServiceName() ?: 'SHARED');
        $nextIndex = $ticketSite->getCurrentStepIndex() + 1;
        $nextStep = $this->stepChain->nextStep($service, $ticketSite->getCurrentStepIndex());

        if ($nextStep === null) {
            $ticketSite->setStatus(TicketSite::STATUS_COMPLETED);
            $ticketSite->setCurrentStepIndex($ticketSite->getTotalSteps());
            $this->addHistory($ticket, null, 'site_completed', 'Site terminé.', $ticketSite->getSiteName());
            return;
        }

        [$stepCode, $title, $description, $target] = $nextStep;
        $nextUser = $this->resolveTarget($target);

        if (!$nextUser) {
            $ticketSite->setStatus(TicketSite::STATUS_BLOCKED);
            $this->addHistory($ticket, null, 'site_blocked', "Aucun utilisateur disponible pour l'étape {$stepCode}.", $ticketSite->getSiteName());
            return;
        }

        $ticketSite->setCurrentStepIndex($nextIndex);
        $ticketSite->setCurrentStepCode($stepCode);
        $ticketSite->setStatus(TicketSite::STATUS_IN_PROGRESS);

        [$targetType, $targetValue] = array_pad(explode(':', $target, 2), 2, null);
        $nextServiceName = $targetType === 'service' ? strtoupper($targetValue) : $service;

        $nextTask = new TicketTask();
        $nextTask->setTicket($ticket);
        $nextTask->setTicketSite($ticketSite);
        $nextTask->setAssignedTo($nextUser);
        $nextTask->setTitle($title . ' — ' . $ticketSite->getSiteName());
        $nextTask->setDescription($description);
        $nextTask->setServiceName($nextServiceName);
        $nextTask->setDepartmentName($nextUser->getDepartment());
        $nextTask->setStepCode($stepCode);
        $nextTask->setStepOrder($nextIndex + 1);
        $nextTask->setStatus(TicketTask::STATUS_PENDING);
        $nextTask->setCreatedAt(new \DateTime());
        $nextTask->setUpdatedAt(new \DateTime());

        $this->em->persist($nextTask);

        $this->addHistory(
            $ticket,
            null,
            'task_created',
            sprintf('Étape suivante (%s) assignée à %s', $title, $nextUser->getUsername() ?? $nextUser->getEmail()),
            $ticketSite->getSiteName()
        );

        $this->notificationService->notify(
            $nextUser,
            NotificationService::TYPE_WORKFLOW_ASSIGNED,
            sprintf('Nouvelle tâche : %s pour le site %s (ticket #%d)', $title, $ticketSite->getSiteName(), $ticket->getId() ?? 0),
            $ticket
        );
    }

    private function resolveTarget(string $target): ?User
    {
        [$type, $value] = array_pad(explode(':', $target, 2), 2, null);

        $qb = $this->em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->setMaxResults(1)
            ->orderBy('u.id', 'ASC');

        if ($type === 'department') {
            $qb->andWhere('LOWER(u.department) = :v')->setParameter('v', strtolower($value));
        } else {
            $qb->andWhere('UPPER(u.service) = :v')->setParameter('v', strtoupper($value));
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function refreshTicketProgress(Ticket $ticket): void
    {
        $this->ticketWorkflowService->refreshTicketProgress($ticket);
    }

    private function addHistory(Ticket $ticket, ?User $user, string $action, ?string $details = null, ?string $site = null): void
    {
        $history = new TicketHistory();
        $history->setTicket($ticket);
        $history->setUser($user);
        $history->setAction($action);
        $history->setDetails($details);
        $history->setSite($site);
        $history->setDateJour(new \DateTime());
        $this->em->persist($history);
    }
}