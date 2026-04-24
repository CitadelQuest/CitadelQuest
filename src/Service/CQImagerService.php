<?php

namespace App\Service;

use App\Entity\ImagerGeneration;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * CQ Imager — orchestrates human-facing image generation against the CQ AI Gateway.
 *
 * Responsibilities:
 *  - proxy the schema-driven model catalog from the gateway
 *  - submit generations, download resulting images, persist them into the
 *    user's File Browser via {@see ProjectFileService}
 *  - keep rich metadata (model, params, seed, cost, task UUID) in the
 *    per-user `imager_generation` table for regenerate / remix / history
 *
 * Sibling of {@see AIToolDiffusionService} (Spirit-facing). Both ultimately
 * call `/api/ai/image-diffusion/generate` on the gateway, but this service
 * exposes the full schema-driven surface (no sub-agent translation step).
 *
 * @see /docs/features/CQ-IMAGER.md
 */
class CQImagerService
{
    /** In-process catalog cache so one page render doesn't make N gateway calls. */
    private ?array $modelsCache = null;

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
        private readonly AiGatewayService $aiGatewayService,
        private readonly ProjectFileService $projectFileService,
        private readonly HttpClientInterface $httpClient,
    ) {}

    /** @return \Doctrine\DBAL\Connection */
    private function getUserDb()
    {
        /** @var User $user */
        $user = $this->security->getUser();
        if (!$user) {
            throw new \RuntimeException('User not authenticated');
        }
        return $this->userDatabaseManager->getDatabaseConnection($user);
    }

    /* ==================================================================
       Model catalog
       ================================================================== */

    /**
     * Fetch the enabled-models catalog from the CQ AI Gateway.
     * Cached in-memory for the lifetime of this service instance.
     *
     * @param bool $forceRefresh  If true, bypass in-process cache AND ask the
     *                            gateway to rebuild its own cache.
     */
    public function getModels(bool $forceRefresh = false): array
    {
        if (!$forceRefresh && $this->modelsCache !== null) {
            return $this->modelsCache;
        }

        [$baseUrl, $apiKey] = $this->getGatewayCredentials();
        $url = rtrim($baseUrl, '/') . '/ai/image-diffusion/models'
             . ($forceRefresh ? '?refresh=1' : '');

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept'        => 'application/json',
                ],
                'timeout' => 20,
            ]);
            $data = json_decode($response->getContent(false), true);
            if (!is_array($data) || empty($data['success'])) {
                $msg = $data['error']['message'] ?? $data['message'] ?? 'Unknown gateway error';
                throw new \RuntimeException('Gateway /models call failed: ' . $msg);
            }
            $this->modelsCache = $data['models'] ?? [];
            return $this->modelsCache;
        } catch (\Throwable $e) {
            error_log('CQImagerService::getModels error: ' . $e->getMessage());
            throw $e;
        }
    }

    /* ==================================================================
       Generate
       ================================================================== */

    /**
     * Generate one or more images and persist them into the File Browser.
     *
     * @param string  $model       AIR id (e.g. "google:4@3")
     * @param array   $flatParams  Flat params as declared by the catalog descriptor
     * @param string  $projectId   CQ project id for file storage (default "general")
     * @param string  $outputDir   Directory path inside the project (e.g. "/uploads/imager")
     * @param ?string $filename    Optional base filename; auto-generated if null
     *
     * @return array {
     *   success: bool,
     *   generations: ImagerGeneration[],
     *   files: array,
     *   taskUUID: string,
     *   model: string,
     *   total_cost_credits: float,
     *   new_balance_credits: float|null,
     * }
     */
    public function generate(
        string $model,
        array $flatParams,
        string $projectId = 'general',
        string $outputDir = '/uploads/imager',
        ?string $filename = null
    ): array {
        if ($model === '') {
            return ['success' => false, 'error' => 'model is required'];
        }
        $prompt = $flatParams['positivePrompt'] ?? $flatParams['prompt'] ?? null;
        if (!is_string($prompt) || trim($prompt) === '') {
            return ['success' => false, 'error' => 'positivePrompt is required'];
        }

        // Keep original params (with cqfile:// tokens) for persistence;
        // only the *gateway-bound* copy gets resolved to data URIs.
        $gatewayParams = $this->resolveCqFileTokens($flatParams);

        // --- Submit to gateway ------------------------------------------
        [$baseUrl, $apiKey] = $this->getGatewayCredentials();
        $endpoint = rtrim($baseUrl, '/') . '/ai/image-diffusion/generate';

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json'    => ['model' => $model, 'params' => $gatewayParams],
                'timeout' => 300, // 5 minutes for image generation
            ]);
            $data = json_decode($response->getContent(false), true);
        } catch (\Throwable $e) {
            error_log('CQImagerService generate HTTP error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Gateway call failed: ' . $e->getMessage()];
        }

        if (!is_array($data) || empty($data['success']) || empty($data['images'])) {
            $msg = $data['error']['message'] ?? $data['message'] ?? 'Image generation failed';
            return ['success' => false, 'error' => $msg, 'raw' => $data];
        }

        // --- Resolve model descriptor for display metadata --------------
        $modelDescriptor = null;
        try {
            foreach ($this->getModels() as $m) {
                if (($m['id'] ?? null) === $model) { $modelDescriptor = $m; break; }
            }
        } catch (\Throwable $e) {
            // non-fatal, descriptor is optional
        }
        $modelSlug = $data['model_slug'] ?? ($modelDescriptor['slug'] ?? null);
        $modelName = $modelDescriptor['name'] ?? null;

        // --- Persist each returned image --------------------------------
        $generations = [];
        $savedFiles  = [];
        $images      = $data['images'] ?? [];
        $taskUUID    = $data['taskUUID'] ?? '';
        $perImageCost = count($images) > 0
            ? ((float) ($data['total_cost_credits'] ?? 0)) / count($images)
            : 0.0;

        $baseName = $filename ?: $this->buildBaseFilename($flatParams);

        foreach ($images as $index => $imageData) {
            $imageUrl = $imageData['imageURL'] ?? null;
            if (!$imageUrl) {
                continue;
            }

            try {
                $imageContent = $this->downloadImageContent($imageUrl);
                if ($imageContent === null) {
                    continue;
                }

                // Determine MIME type from binary
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($imageContent) ?: 'image/jpeg';

                // Derive extension
                $ext = $this->extensionForMime($mimeType)
                    ?? pathinfo($baseName, PATHINFO_EXTENSION)
                    ?: 'jpg';

                $finalName = $this->uniqueFilename($baseName, $ext, $index);

                $projectFile = $this->projectFileService->createFile(
                    $projectId,
                    $outputDir,
                    $finalName,
                    $imageContent,
                    $mimeType
                );
                if (!$projectFile) {
                    continue;
                }

                // Persist metadata
                $gen = new ImagerGeneration(
                    $projectId,
                    $projectFile->getId(),
                    $model,
                    $flatParams,
                    $modelSlug,
                    $modelName
                );
                $gen->setSeed($imageData['seed'] ?? null);
                $gen->setCostCredits($perImageCost);
                $gen->setWidth($flatParams['width'] ?? null);
                $gen->setHeight($flatParams['height'] ?? null);
                $gen->setImageUrl($imageUrl);
                $gen->setTaskUuid($taskUUID ?: null);

                $this->insertGeneration($gen);

                $generations[] = $gen;
                $savedFiles[]  = [
                    'id'        => $projectFile->getId(),
                    'projectId' => $projectId,
                    'path'      => $outputDir,
                    'name'      => $finalName,
                    'mimeType'  => $mimeType,
                    'seed'      => $imageData['seed'] ?? null,
                    'imageURL'  => $imageUrl, // Runware URL (fast display, TTL ~30d)
                ];
            } catch (\Throwable $e) {
                error_log('CQImagerService save image error: ' . $e->getMessage());
            }
        }

        return [
            'success'             => count($generations) > 0,
            'generations'         => $generations,
            'files'               => $savedFiles,
            'taskUUID'            => $taskUUID,
            'model'               => $model,
            'model_slug'          => $modelSlug,
            'model_name'          => $modelName,
            'total_cost_credits'  => (float) ($data['total_cost_credits'] ?? 0),
            'new_balance_credits' => $data['new_balance_credits'] ?? null,
        ];
    }

    /* ==================================================================
       History (CRUD)
       ================================================================== */

    /**
     * List generations, most recent first.
     *
     * @param array{projectId?:string,projectFileId?:string,model?:string,limit?:int,offset?:int} $filters
     * @return ImagerGeneration[]
     */
    public function listHistory(array $filters = []): array
    {
        $db = $this->getUserDb();

        $sql = 'SELECT * FROM imager_generation WHERE 1=1';
        $params = [];

        if (!empty($filters['projectId'])) {
            $sql .= ' AND project_id = ?';
            $params[] = $filters['projectId'];
        }
        if (!empty($filters['projectFileId'])) {
            $sql .= ' AND project_file_id = ?';
            $params[] = $filters['projectFileId'];
        }
        if (!empty($filters['model'])) {
            $sql .= ' AND model = ?';
            $params[] = $filters['model'];
        }

        $sql .= ' ORDER BY created_at DESC';

        $limit  = max(1, min(500, (int) ($filters['limit']  ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $sql .= ' LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        $rows = $db->executeQuery($sql, $params)->fetchAllAssociative();
        return array_map(fn($r) => ImagerGeneration::fromArray($r), $rows);
    }

    public function getGeneration(string $id): ?ImagerGeneration
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery('SELECT * FROM imager_generation WHERE id = ?', [$id])
                  ->fetchAssociative();
        return $row ? ImagerGeneration::fromArray($row) : null;
    }

    /**
     * Delete a generation row. If $deleteFile is true, also remove the
     * linked project_file (source of truth lives in the file browser, so
     * default is to keep the file and only drop the metadata).
     */
    public function deleteGeneration(string $id, bool $deleteFile = false): bool
    {
        $gen = $this->getGeneration($id);
        if (!$gen) {
            return false;
        }

        if ($deleteFile) {
            try {
                $this->projectFileService->delete($gen->getProjectFileId());
            } catch (\Throwable $e) {
                error_log('CQImagerService deleteGeneration file error: ' . $e->getMessage());
            }
        }

        $db = $this->getUserDb();
        $db->executeStatement('DELETE FROM imager_generation WHERE id = ?', [$id]);
        return true;
    }

    /* ==================================================================
       Internals
       ================================================================== */

    /** @return array{0:string,1:string} [baseUrl, apiKey] */
    private function getGatewayCredentials(): array
    {
        $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
        if (!$gateway) {
            throw new \RuntimeException('CQ AI Gateway is not configured for this user');
        }
        return [$gateway->getApiEndpointUrl(), $gateway->getApiKey()];
    }

    private function insertGeneration(ImagerGeneration $gen): void
    {
        $db = $this->getUserDb();
        $db->executeStatement(
            'INSERT INTO imager_generation
                (id, project_id, project_file_id, model, model_slug, model_name,
                 params_json, seed, cost_credits, width, height, image_url, task_uuid, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $gen->getId(),
                $gen->getProjectId(),
                $gen->getProjectFileId(),
                $gen->getModel(),
                $gen->getModelSlug(),
                $gen->getModelName(),
                json_encode($gen->getParams(), JSON_UNESCAPED_SLASHES),
                $gen->getSeed(),
                $gen->getCostCredits(),
                $gen->getWidth(),
                $gen->getHeight(),
                $gen->getImageUrl(),
                $gen->getTaskUuid(),
                $gen->getCreatedAt()->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Resolve CQ File Browser tokens (`cqfile://<project-file-id>` — optional
     * `#filename` fragment is stripped) in string / string[] param values
     * into data URIs that Runware can ingest directly.
     *
     * Non-token values are passed through unchanged. The returned array is
     * safe to send to the gateway; the original `$flatParams` (with tokens)
     * stays intact so history preserves the user's intent without bloating
     * the params_json with base64 data.
     */
    private function resolveCqFileTokens(array $flatParams): array
    {
        foreach ($flatParams as $key => $value) {
            if (is_string($value)) {
                $resolved = $this->resolveSingleCqFileToken($value);
                if ($resolved !== null) {
                    $flatParams[$key] = $resolved;
                }
            } elseif (is_array($value)) {
                $flatParams[$key] = array_values(array_filter(
                    array_map(
                        fn($v) => is_string($v) ? ($this->resolveSingleCqFileToken($v) ?? $v) : $v,
                        $value
                    ),
                    static fn($v) => $v !== null && $v !== ''
                ));
            }
        }
        return $flatParams;
    }

    /**
     * @return string|null  Data URI if $value is a cqfile:// token (null on failure),
     *                      or null if $value is not a cqfile:// token (leave as-is).
     */
    private function resolveSingleCqFileToken(string $value): ?string
    {
        if (!str_starts_with($value, 'cqfile://')) {
            return null; // not a token — caller keeps original value
        }

        $id = substr($value, strlen('cqfile://'));
        // Strip optional #filename suffix used purely for UI display
        if (($hash = strpos($id, '#')) !== false) {
            $id = substr($id, 0, $hash);
        }
        $id = trim($id);
        if ($id === '') {
            return '';
        }

        try {
            $content = $this->projectFileService->getFileContent($id);
            // For images, ProjectFileService returns a ready data URI.
            if (is_string($content) && str_starts_with($content, 'data:')) {
                return $content;
            }
            // Fallback — wrap as octet-stream (Runware won't love it, but
            // it's better than silently sending a 'cqfile://…' string).
            if (is_string($content) && $content !== '') {
                return 'data:application/octet-stream;base64,' . base64_encode($content);
            }
        } catch (\Throwable $e) {
            error_log('CQImagerService::resolveSingleCqFileToken failed for ' . $id . ': ' . $e->getMessage());
        }
        return ''; // resolved-but-empty → caller drops it
    }

    /**
     * Download a remote image (URL) or decode a data URI / base64 string.
     * Returns the raw binary content or null on failure.
     */
    private function downloadImageContent(string $source): ?string
    {
        // data URI
        if (str_starts_with($source, 'data:')) {
            $parts = explode(',', $source, 2);
            if (count($parts) !== 2) { return null; }
            $decoded = base64_decode($parts[1], true);
            return $decoded === false ? null : $decoded;
        }

        // remote URL — use HttpClient for consistent timeout/error behavior
        try {
            $resp = $this->httpClient->request('GET', $source, [
                'timeout' => 60,
                'headers' => ['User-Agent' => 'CitadelQuest CQ Imager'],
            ]);
            if ($resp->getStatusCode() !== 200) {
                return null;
            }
            return $resp->getContent(false);
        } catch (\Throwable $e) {
            error_log('CQImagerService downloadImageContent error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Build a reasonable default filename from the prompt + timestamp.
     */
    private function buildBaseFilename(array $params): string
    {
        $prompt = (string) ($params['positivePrompt'] ?? $params['prompt'] ?? 'image');
        // Slugify: lowercase, keep alnum + dash, collapse whitespace, trim
        $slug = strtolower($prompt);
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? 'image';
        $slug = trim($slug, '-');
        if ($slug === '') { $slug = 'image'; }
        $slug = substr($slug, 0, 40); // keep filenames reasonable

        return sprintf('img_%s_%s.jpg', date('YmdHis'), $slug);
    }

    /**
     * Ensure each image in a batch gets a unique filename.
     */
    private function uniqueFilename(string $base, string $ext, int $index): string
    {
        $info = pathinfo($base);
        $stem = $info['filename'] ?? 'img';
        $extension = strtolower($ext ?: ($info['extension'] ?? 'jpg'));
        if ($index > 0) {
            $stem .= '_' . ($index + 1);
        }
        return $stem . '.' . $extension;
    }

    private function extensionForMime(string $mime): ?string
    {
        return match (strtolower($mime)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png'               => 'png',
            'image/webp'              => 'webp',
            'image/gif'               => 'gif',
            default                   => null,
        };
    }
}
