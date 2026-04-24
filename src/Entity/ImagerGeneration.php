<?php

namespace App\Entity;

use JsonSerializable;

/**
 * CQ Imager Generation — metadata record for each AI-generated image.
 *
 * The actual image file lives in the user's File Browser (via ProjectFileService).
 * This entity captures everything needed to reproduce / remix / display history:
 *   - which model was used (AIR id + slug + human name)
 *   - full flat param set at time of generation (JSON)
 *   - seed, dimensions, cost, Runware task UUID
 *   - foreign key to project_file row
 *
 * @see /docs/features/CQ-IMAGER.md
 */
class ImagerGeneration implements JsonSerializable
{
    private string $id;
    private string $projectId;
    private string $projectFileId;
    private string $model;
    private ?string $modelSlug;
    private ?string $modelName;
    private array $params;
    private ?int $seed;
    private ?float $costCredits;
    private ?int $width;
    private ?int $height;
    private ?string $imageUrl;
    private ?string $taskUuid;
    private \DateTimeInterface $createdAt;

    public function __construct(
        string $projectId,
        string $projectFileId,
        string $model,
        array $params,
        ?string $modelSlug = null,
        ?string $modelName = null
    ) {
        $this->id            = uuid_create();
        $this->projectId     = $projectId;
        $this->projectFileId = $projectFileId;
        $this->model         = $model;
        $this->modelSlug     = $modelSlug;
        $this->modelName     = $modelName;
        $this->params        = $params;
        $this->seed          = null;
        $this->costCredits   = null;
        $this->width         = null;
        $this->height        = null;
        $this->imageUrl      = null;
        $this->taskUuid      = null;
        $this->createdAt     = new \DateTime();
    }

    // Getters
    public function getId(): string { return $this->id; }
    public function getProjectId(): string { return $this->projectId; }
    public function getProjectFileId(): string { return $this->projectFileId; }
    public function getModel(): string { return $this->model; }
    public function getModelSlug(): ?string { return $this->modelSlug; }
    public function getModelName(): ?string { return $this->modelName; }
    public function getParams(): array { return $this->params; }
    public function getSeed(): ?int { return $this->seed; }
    public function getCostCredits(): ?float { return $this->costCredits; }
    public function getWidth(): ?int { return $this->width; }
    public function getHeight(): ?int { return $this->height; }
    public function getImageUrl(): ?string { return $this->imageUrl; }
    public function getTaskUuid(): ?string { return $this->taskUuid; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }

    // Setters
    public function setId(string $id): self { $this->id = $id; return $this; }
    public function setProjectId(string $projectId): self { $this->projectId = $projectId; return $this; }
    public function setProjectFileId(string $projectFileId): self { $this->projectFileId = $projectFileId; return $this; }
    public function setModel(string $model): self { $this->model = $model; return $this; }
    public function setModelSlug(?string $modelSlug): self { $this->modelSlug = $modelSlug; return $this; }
    public function setModelName(?string $modelName): self { $this->modelName = $modelName; return $this; }
    public function setParams(array $params): self { $this->params = $params; return $this; }
    public function setSeed(?int $seed): self { $this->seed = $seed; return $this; }
    public function setCostCredits(?float $costCredits): self { $this->costCredits = $costCredits; return $this; }
    public function setWidth(?int $width): self { $this->width = $width; return $this; }
    public function setHeight(?int $height): self { $this->height = $height; return $this; }
    public function setImageUrl(?string $imageUrl): self { $this->imageUrl = $imageUrl; return $this; }
    public function setTaskUuid(?string $taskUuid): self { $this->taskUuid = $taskUuid; return $this; }
    public function setCreatedAt(\DateTimeInterface $createdAt): self { $this->createdAt = $createdAt; return $this; }

    public function jsonSerialize(): array
    {
        return [
            'id'            => $this->id,
            'projectId'     => $this->projectId,
            'projectFileId' => $this->projectFileId,
            'model'         => $this->model,
            'modelSlug'     => $this->modelSlug,
            'modelName'     => $this->modelName,
            'params'        => $this->params,
            'seed'          => $this->seed,
            'costCredits'   => $this->costCredits,
            'width'         => $this->width,
            'height'        => $this->height,
            'imageUrl'      => $this->imageUrl,
            'taskUuid'      => $this->taskUuid,
            'createdAt'     => $this->createdAt->format('c'),
        ];
    }

    public static function fromArray(array $data): self
    {
        $params = [];
        if (!empty($data['params_json'])) {
            $decoded = json_decode((string) $data['params_json'], true);
            if (is_array($decoded)) {
                $params = $decoded;
            }
        }

        $gen = new self(
            $data['project_id'],
            $data['project_file_id'],
            $data['model'],
            $params,
            $data['model_slug'] ?? null,
            $data['model_name'] ?? null
        );

        $gen->setId($data['id']);
        if (isset($data['seed']) && $data['seed'] !== null) {
            $gen->setSeed((int) $data['seed']);
        }
        if (isset($data['cost_credits']) && $data['cost_credits'] !== null) {
            $gen->setCostCredits((float) $data['cost_credits']);
        }
        if (isset($data['width']) && $data['width'] !== null) {
            $gen->setWidth((int) $data['width']);
        }
        if (isset($data['height']) && $data['height'] !== null) {
            $gen->setHeight((int) $data['height']);
        }
        $gen->setImageUrl($data['image_url'] ?? null);
        $gen->setTaskUuid($data['task_uuid'] ?? null);
        if (!empty($data['created_at'])) {
            $gen->setCreatedAt(new \DateTime($data['created_at']));
        }

        return $gen;
    }
}
