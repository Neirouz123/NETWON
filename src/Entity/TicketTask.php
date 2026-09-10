<?php
// src/Entity/TicketTask.php

namespace App\Entity;

use App\Repository\TicketTaskRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TicketTaskRepository::class)]
class TicketTask
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_WAITING_IP = 'waiting_ip';
    public const STATUS_WAITING_DEPLOYMENT = 'waiting_deployment';

    public const STEP_FH_ETUDE_PREREQUIS = 'fh_etude_prerequis';
    public const STEP_FH_MAJ_CAPACITE = 'fh_maj_capacite';
    public const STEP_FH_ING_TRANS_CAP = 'fh_ing_trans_cap';
    public const STEP_FH_MLO = 'fh_mlo';
    public const STEP_FH_LLD = 'fh_lld';
    public const STEP_FH_EXECUTION_WO = 'fh_execution_wo';
    public const STEP_FH_VALIDATION_CAPILLAIRE = 'fh_validation_capillaire';
    public const STEP_FH_MLO_VALIDATION = 'fh_mlo_validation';

    public const STEP_FO_INITIAL_ANALYSIS = 'fo_initial_analysis';
    public const STEP_FO_WO_IP_CREATION = 'fo_wo_ip_creation';
    public const STEP_FO_DEPLOYMENT_PLANNING = 'fo_deployment_planning';
    public const STEP_FO_SITE_EXECUTION = 'fo_site_execution';
    public const STEP_FO_FINAL_VALIDATION = 'fo_final_validation';
    public const STEP_FO_TICKET_CLOSURE = 'fo_ticket_closure';
    public const STEP_FO_KPI_CHECK = 'fo_kpi_check';
    public const STEP_FO_CAPILLAIRE_STUDY = 'fo_capillaire_study';
    public const STEP_FO_CAPILLAIRE_DEPLOYMENT = 'fo_capillaire_deployment';
    public const STEP_FO_CAPILLAIRE_VALIDATION = 'fo_capillaire_validation';
    public const STEP_FO_IP_SWAP_ANALYSIS = 'fo_ip_swap_analysis';

    public const STEP_ENGINEERING_IP = 'engineering_ip';
    public const STEP_ANALYSE_COMPLEMENTAIRE = 'analyse_complementaire';
    public const STEP_CAPILLAIRE_FO = 'capillaire_fo';
    public const STEP_DEPLOIEMENT_FO = 'deploiement_fo';
    public const STEP_VALIDATION_FO = 'validation_fo';
    public const STEP_EXECUTION_SITE = 'execution_site';
    public const STEP_VALIDATION_FINALE = 'validation_finale';
    public const STEP_FERMETURE = 'fermeture_ticket';
    public const STEP_VERIFICATION_KPI = 'verification_kpi';
    public const STEP_SUPERUSER_VALIDATION = 'superuser_validation';

    public const STEP_DEPLOIEMENT_PLANIFICATION = 'deploiement_planification';
    public const STEP_DEPLOIEMENT_EXECUTION = 'deploiement_execution';
    public const STEP_DEPLOIEMENT_VALIDATION = 'deploiement_validation';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Ticket $ticket = null;

    // === AJOUT : lien direct vers le site individuel traité par cette tâche ===
    #[ORM\ManyToOne(targetEntity: TicketSite::class, inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?TicketSite $ticketSite = null;

    public function getTicketSite(): ?TicketSite
    {
        return $this->ticketSite;
    }
    public function setTicketSite(?TicketSite $ticketSite): static
    {
        $this->ticketSite = $ticketSite;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $assignedTo = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $serviceName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $departmentName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $stepCode = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $decision = null;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column]
    private bool $requiresCapture = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $capturePath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $proofFile = null;

    #[ORM\Column(type: 'integer')]
    private int $stepOrder = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $startedAt = null;

    public function getStartedAt(): ?\DateTimeInterface
    {
        return $this->startedAt;
    }
    public function setStartedAt(?\DateTimeInterface $startedAt): static
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $cardType = null;

    #[ORM\Column(nullable: true)]
    private ?float $measuredCapacity = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $ipDecision = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $woIpReference = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $siteDecisions = null;

    /**
     * @deprecated conservé uniquement pour lire les anciennes tâches créées
     * avant l'introduction de la relation ticketSite. Ne plus écrire dedans.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $siteData = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $woIpContent = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $fhFields = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $deploiementData = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getDeploiementData(): ?array
    {
        return $this->deploiementData;
    }
    public function setDeploiementData(?array $deploiementData): self
    {
        $this->deploiementData = $deploiementData;
        return $this;
    }

    public function getWoIpContent(): ?string
    {
        return $this->woIpContent;
    }
    public function setWoIpContent(?string $woIpContent): self
    {
        $this->woIpContent = $woIpContent;
        return $this;
    }

    public function getFhFields(): ?array
    {
        return $this->fhFields;
    }
    public function setFhFields(?array $fhFields): self
    {
        $this->fhFields = $fhFields;
        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicket(): ?Ticket
    {
        return $this->ticket;
    }
    public function setTicket(?Ticket $ticket): static
    {
        $this->ticket = $ticket;
        return $this;
    }

    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }
    public function setAssignedTo(?User $assignedTo): static
    {
        $this->assignedTo = $assignedTo;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }
    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getServiceName(): ?string
    {
        return $this->serviceName;
    }
    public function setServiceName(?string $serviceName): static
    {
        $this->serviceName = $serviceName;
        return $this;
    }

    public function getDepartmentName(): ?string
    {
        return $this->departmentName;
    }
    public function setDepartmentName(?string $departmentName): static
    {
        $this->departmentName = $departmentName;
        return $this;
    }

    public function getStepCode(): ?string
    {
        return $this->stepCode;
    }
    public function setStepCode(?string $stepCode): static
    {
        $this->stepCode = $stepCode;
        return $this;
    }

    public function getDecision(): ?string
    {
        return $this->decision;
    }
    public function setDecision(?string $decision): static
    {
        $this->decision = $decision;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }
    public function setComment(?string $comment): static
    {
        $this->comment = $comment;
        return $this;
    }

    public function isRequiresCapture(): bool
    {
        return $this->requiresCapture;
    }
    public function setRequiresCapture(bool $requiresCapture): static
    {
        $this->requiresCapture = $requiresCapture;
        return $this;
    }

    public function getCapturePath(): ?string
    {
        return $this->capturePath;
    }
    public function setCapturePath(?string $capturePath): static
    {
        $this->capturePath = $capturePath;
        return $this;
    }

    public function getProofFile(): ?string
    {
        return $this->proofFile;
    }
    public function setProofFile(?string $proofFile): static
    {
        $this->proofFile = $proofFile;
        return $this;
    }

    public function getStepOrder(): int
    {
        return $this->stepOrder;
    }
    public function setStepOrder(int $stepOrder): static
    {
        $this->stepOrder = $stepOrder;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }
    public function setCompletedAt(?\DateTimeInterface $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getCardType(): ?string
    {
        return $this->cardType;
    }
    public function setCardType(?string $cardType): static
    {
        $this->cardType = $cardType;
        return $this;
    }

    public function getMeasuredCapacity(): ?float
    {
        return $this->measuredCapacity;
    }
    public function setMeasuredCapacity(?float $measuredCapacity): static
    {
        $this->measuredCapacity = $measuredCapacity;
        return $this;
    }

    public function getIpDecision(): ?string
    {
        return $this->ipDecision;
    }
    public function setIpDecision(?string $ipDecision): static
    {
        $this->ipDecision = $ipDecision;
        return $this;
    }

    public function getWoIpReference(): ?string
    {
        return $this->woIpReference;
    }
    public function setWoIpReference(?string $woIpReference): static
    {
        $this->woIpReference = $woIpReference;
        return $this;
    }

    public function getSiteDecisions(): ?array
    {
        return $this->siteDecisions;
    }
    public function setSiteDecisions(?array $siteDecisions): static
    {
        $this->siteDecisions = $siteDecisions;
        return $this;
    }

    /** @deprecated utiliser getTicketSite() */
    public function getSiteData(): ?array
    {
        return $this->siteData;
    }
    /** @deprecated utiliser setTicketSite() */
    public function setSiteData(?array $siteData): static
    {
        $this->siteData = $siteData;
        return $this;
    }

    public function isDone(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_COMPLETED], true);
    }
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }
}
