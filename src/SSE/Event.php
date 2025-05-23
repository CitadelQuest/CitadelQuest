<?php

namespace App\SSE;

class Event
{
    public function __construct(
        private readonly string $type,
        private readonly mixed $data,
        private ?string $id = null,
        private readonly ?\DateTimeInterface $timestamp = null
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getTimestamp(): \DateTimeInterface
    {
        return $this->timestamp ?? new \DateTimeImmutable();
    }

    public function toJson(): string
    {
        return json_encode($this->data, JSON_THROW_ON_ERROR);
    }
}
