<?php
// src/Service/WorkflowAutoAssigner.php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketSite;
use App\Entity\TicketTask;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class WorkflowAutoAssigner
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepo,
        private LoggerInterface $logger,
        private NotificationService $notificationService,
        private WorkflowStepChain $stepChain
    ) {}

    /**
     * Crée UNE tâche par TicketSite (jamais un lot multi-sites), pour la
     * première étape de la chaîne correspondant au service du site.
     *
     * Le point d'entrée est résolu par DÉPARTEMENT exact (jamais un
     * "service seul", qui pouvait retomber sur n'importe quel utilisateur
     * du service — c'était la cause du bug où tout finissait toujours
     * chez Ingénierie Capillaire pour FH) :
     *   - FH  -> ingenierie_capillaire (fh_etude_prerequis)
     *   - FO  -> ingenierie_ip (fo_initial_analysis)
     *   - DEPLOIEMENT -> deploiement_telecom
     *   - SHARED -> pas de département dédié, reste sur le service
     *
     * Répartition round-robin entre les users du département/service
     * cible pour ne pas tout envoyer au même utilisateur.
     *
     * @param TicketSite[] $ticketSites
     * @return User[] utilisateurs notifiés (dédupliqués)
     */
    public function assignUsersForTicketSites(array $ticketSites, Ticket $ticket, User $currentUser): array
    {
        $assignedUsers = [];
        $roundRobinCursor = [];

        foreach ($ticketSites as $ticketSite) {
            $service = strtoupper($ticketSite->getServiceName() ?: 'SHARED');

            $entryDepartment = match ($service) {
                'FH' => 'ingenierie_capillaire',
                'FO' => 'ingenierie_ip',
                'DEPLOIEMENT' => 'deploiement_telecom',
                default => null,
            };

            $user = $entryDepartment
                ? $this->pickUserForDepartment($entryDepartment, $roundRobinCursor)
                : $this->pickUserForService($service, $roundRobinCursor);

            if (!$user) {
                $user = $this->pickUserForService('SHARED', $roundRobinCursor) ?? $currentUser;
                $this->logger->warning(
                    'Aucun utilisateur trouvé pour {target}, fallback appliqué.',
                    ['target' => $entryDepartment ?? $service]
                );
            }

            [$stepCode, $title, $description] = $this->stepChain->firstStep($service);
            $totalSteps = $this->stepChain->totalStepsFor($service);

            $ticketSite->setTotalSteps($totalSteps);
            $ticketSite->setCurrentStepIndex(0);
            $ticketSite->setCurrentStepCode($stepCode);
            $ticketSite->setStatus(TicketSite::STATUS_IN_PROGRESS);

            $task = new TicketTask();
            $task->setTicket($ticket);
            $task->setTicketSite($ticketSite);
            $task->setAssignedTo($user);
            $task->setTitle($title . ' — ' . $ticketSite->getSiteName());
            $task->setDescription($description);
            $task->setServiceName($service);
            $task->setDepartmentName($entryDepartment ?? $user->getDepartment());
            $task->setStatus(TicketTask::STATUS_PENDING);
            $task->setStepCode($stepCode);
            $task->setStepOrder(1);

            $this->em->persist($task);

            $this->notificationService->notify(
                $user,
                NotificationService::TYPE_WORKFLOW_ASSIGNED,
                sprintf(
                    'Nouvelle tâche : %s pour le ticket #%d - %s',
                    $task->getTitle(),
                    $ticket->getId() ?? 0,
                    $ticket->getTitle()
                ),
                $ticket
            );

            $assignedUsers[$user->getId()] = $user;
        }

        $this->em->flush();

        return array_values($assignedUsers);
    }

    private function pickUserForService(string $service, array &$cursor): ?User
    {
        $users = array_values(array_filter(
            $this->userRepo->findBy(['service' => $service]),
            fn(User $u) => in_array('ROLE_USER', $u->getRoles(), true)
        ));

        if (empty($users)) {
            return null;
        }

        $i = $cursor[$service] ?? 0;
        $picked = $users[$i % count($users)];
        $cursor[$service] = $i + 1;

        return $picked;
    }

    private function pickUserForDepartment(string $department, array &$cursor): ?User
    {
        $users = array_values(array_filter(
            $this->userRepo->findBy(['department' => $department]),
            fn(User $u) => in_array('ROLE_USER', $u->getRoles(), true)
        ));

        if (empty($users)) {
            return null;
        }

        $key = 'dept:' . $department;
        $i = $cursor[$key] ?? 0;
        $picked = $users[$i % count($users)];
        $cursor[$key] = $i + 1;

        return $picked;
    }
}