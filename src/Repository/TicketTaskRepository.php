<?php

namespace App\Repository;

use App\Entity\TicketTask;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TicketTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketTask::class);
    }

    public function findByAssignedUser(User $user): array
    {
        return $this->createQueryBuilder('tt')
            ->leftJoin('tt.ticket', 't')->addSelect('t')
            ->leftJoin('tt.ticketSite', 'ts')->addSelect('ts')
            ->andWhere('tt.assignedTo = :user')
            ->setParameter('user', $user)
            ->orderBy('tt.createdAt', 'DESC')
            ->getQuery()->getResult();
    }

    public function findPendingByAssignedUser(User $user): array
    {
        return $this->createQueryBuilder('tt')
            ->leftJoin('tt.ticket', 't')->addSelect('t')
            ->leftJoin('tt.ticketSite', 'ts')->addSelect('ts')
            ->andWhere('tt.assignedTo = :user')
            ->andWhere('tt.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', [TicketTask::STATUS_PENDING, TicketTask::STATUS_IN_PROGRESS, TicketTask::STATUS_BLOCKED])
            ->orderBy('tt.createdAt', 'DESC')
            ->getQuery()->getResult();
    }

    public function findActiveByAssignedUser(User $user): array
    {
        return $this->findPendingByAssignedUser($user);
    }

    public function findOverdueTasksByUser(User $user): array
    {
        return $this->createQueryBuilder('tt')
            ->join('tt.ticket', 't')->addSelect('t')
            ->andWhere('tt.assignedTo = :user')
            ->andWhere('tt.status != :done')
            ->andWhere('t.deadlineAt IS NOT NULL')
            ->andWhere('t.deadlineAt < :now')
            ->setParameter('user', $user)
            ->setParameter('done', TicketTask::STATUS_DONE)
            ->setParameter('now', new \DateTime())
            ->orderBy('t.deadlineAt', 'ASC')
            ->getQuery()->getResult();
    }

    public function findOverdueTasks(): array
    {
        return $this->createQueryBuilder('tt')
            ->join('tt.ticket', 't')->addSelect('t')
            ->leftJoin('tt.assignedTo', 'u')->addSelect('u')
            ->andWhere('tt.status NOT IN (:finalStatuses)')
            ->andWhere('tt.assignedTo IS NOT NULL')
            ->andWhere('(t.deadlineAt < :now OR t.deadline < :now)')
            ->andWhere('t.status NOT IN (:statuses)')
            ->setParameter('finalStatuses', [TicketTask::STATUS_DONE, TicketTask::STATUS_COMPLETED])
            ->setParameter('now', new \DateTime())
            ->setParameter('statuses', ['completed', 'closed'])
            ->orderBy('t.deadlineAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findDistinctServices(): array
    {
        $rows = $this->createQueryBuilder('tt')
            ->select('DISTINCT tt.serviceName AS serviceName')
            ->andWhere('tt.serviceName IS NOT NULL')
            ->orderBy('tt.serviceName', 'ASC')
            ->getQuery()->getArrayResult();

        return array_values(array_filter(array_map(fn ($row) => $row['serviceName'], $rows)));
    }

    public function findAllWithTickets(?string $service = null): array
    {
        $qb = $this->createQueryBuilder('tt')
            ->leftJoin('tt.ticket', 't')->addSelect('t')
            ->leftJoin('tt.assignedTo', 'u')->addSelect('u')
            ->orderBy('t.createdAt', 'DESC');

        if ($service) {
            $qb->andWhere('tt.serviceName = :service')->setParameter('service', $service);
        }

        return $qb->getQuery()->getResult();
    }

    public function countTicketsInProgressByService(): array
    {
        return $this->getEntityManager()->createQuery(
            'SELECT tt.serviceName AS service, COUNT(DISTINCT t.id) AS total
             FROM App\\Entity\\TicketTask tt JOIN tt.ticket t
             WHERE tt.serviceName IS NOT NULL AND t.status IN (:statuses)
             GROUP BY tt.serviceName ORDER BY tt.serviceName ASC'
        )->setParameter('statuses', ['open', 'in_progress'])->getArrayResult();
    }

    public function countClosedTicketsByService(): array
    {
        return $this->getEntityManager()->createQuery(
            'SELECT tt.serviceName AS service, COUNT(DISTINCT t.id) AS total
             FROM App\\Entity\\TicketTask tt JOIN tt.ticket t
             WHERE tt.serviceName IS NOT NULL AND t.status = :status
             GROUP BY tt.serviceName ORDER BY tt.serviceName ASC'
        )->setParameter('status', 'closed')->getArrayResult();
    }

    public function countOverdueTicketsByService(): array
    {
        return $this->getEntityManager()->createQuery(
            'SELECT tt.serviceName AS service, COUNT(DISTINCT t.id) AS total
             FROM App\\Entity\\TicketTask tt JOIN tt.ticket t
             WHERE tt.serviceName IS NOT NULL AND t.deadlineAt IS NOT NULL
             AND t.deadlineAt < :now AND t.status != :closed
             GROUP BY tt.serviceName ORDER BY tt.serviceName ASC'
        )->setParameter('now', new \DateTime())->setParameter('closed', 'closed')->getArrayResult();
    }

    public function countTotalTicketsByService(): array
    {
        return $this->getEntityManager()->createQuery(
            'SELECT tt.serviceName AS service, COUNT(DISTINCT t.id) AS total
             FROM App\\Entity\\TicketTask tt JOIN tt.ticket t
             WHERE tt.serviceName IS NOT NULL GROUP BY tt.serviceName ORDER BY tt.serviceName ASC'
        )->getArrayResult();
    }

    /**
     * Tâches actives d'un user, avec le site déjà chargé (évite le N+1
     * et remplace le filtrage manuel par serviceName fait auparavant
     * côté contrôleur).
     */
    public function findByAssignedUserWithSite(User $user): array
    {
        return $this->createQueryBuilder('tt')
            ->leftJoin('tt.ticket', 't')->addSelect('t')
            ->leftJoin('tt.ticketSite', 'ts')->addSelect('ts')
            ->andWhere('tt.assignedTo = :user')
            ->setParameter('user', $user)
            ->orderBy('tt.createdAt', 'ASC')
            ->getQuery()->getResult();
    }
}